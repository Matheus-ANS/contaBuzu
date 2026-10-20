<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
session_start();

// Proteção da página: requer login ativo
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

$usuarioId = (int)$_SESSION['usuario_id'];

// Sincroniza privilégios do usuário diretamente com o banco de dados e valida status ativo
try {
    $stmtUserCheck = $pdo->prepare("SELECT acesso, cargo, nome, login, ativo FROM usuarios WHERE idusuarios = ? LIMIT 1");
    $stmtUserCheck->execute([$usuarioId]);
    $userData = $stmtUserCheck->fetch();
    if ($userData) {
        if (isset($userData['ativo']) && (int)$userData['ativo'] === 0) {
            session_unset();
            session_destroy();
            header('Location: login.php?erro=inativo');
            exit;
        }
        $_SESSION['acesso'] = (int)$userData['acesso'];
        $_SESSION['cargo']  = isset($userData['cargo']) ? $userData['cargo'] : '';
        $_SESSION['nome']   = $userData['nome'];
        $_SESSION['login']  = $userData['login'];
        $_SESSION['ativo']  = (int)$userData['ativo'];
    } else {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
} catch (PDOException $e) {
    // Em caso de erro na consulta, utiliza valores prévios da sessão
}

$loggedNome   = isset($_SESSION['nome']) ? $_SESSION['nome'] : 'Usuário';
$loggedLogin  = isset($_SESSION['login']) ? $_SESSION['login'] : '';
$loggedAcesso = isset($_SESSION['acesso']) ? (int)$_SESSION['acesso'] : 0;
$loggedCargo  = isset($_SESSION['cargo']) ? trim((string)$_SESSION['cargo']) : '';

// Definição de permissões e papéis
$isAdmin   = ($loggedAcesso === 1) || (strcasecmp($loggedCargo, 'administrador') === 0) || (strcasecmp($loggedCargo, 'admin') === 0);
$isGerente = ($loggedAcesso === 2) || (strcasecmp($loggedCargo, 'gerente') === 0) || (strcasecmp($loggedCargo, 'supervisor') === 0);
$canViewSolicitacoes = ($isAdmin || $isGerente);

// Inicial da foto / avatar
$nameParts = explode(' ', trim((string)$loggedNome));
$initials = '';
if (count($nameParts) > 0 && $nameParts[0] !== '') {
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
    }
}
if (!$initials) $initials = 'US';

$mensagemSucesso = '';
$mensagemErro = '';

if (!function_exists('calcPercentualStr')) {
    function calcPercentualStr($total) {
        $t = (int)$total;
        if ($t >= 100) return '120%';
        if ($t >= 82) return '100%';
        if ($t >= 54) return '65%';
        if ($t >= 42) return '50%';
        if ($t >= 30) return '35%';
        if ($t >= 13) return '15%';
        return '0%';
    }
}

if (isset($_GET['login']) && $_GET['login'] === 'success') {
    $mensagemSucesso = "Bem-vindo ao sistema contaBuzu, " . htmlspecialchars($loggedNome) . "!";
}

// ==========================================
// PROCESSAMENTO DE AÇÕES (POST / AJAX)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['ajax']);
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    try {
        // 1. Iniciar nova contagem
        if ($action === 'iniciar_contagem') {
            $idLinha        = !empty($_POST['idLinha']) ? (int)$_POST['idLinha'] : null;
            $idVeiculo      = !empty($_POST['idVeiculo']) ? (int)$_POST['idVeiculo'] : null;
            $idLocal        = !empty($_POST['idLocal']) ? (int)$_POST['idLocal'] : null;
            $sentido        = !empty($_POST['sentido']) ? trim($_POST['sentido']) : 'Ida';
            $condTempo      = !empty($_POST['condTempo']) ? trim($_POST['condTempo']) : (!empty($_POST['condicaoTempo']) ? trim($_POST['condicaoTempo']) : 'bom');
            $condicaoTempo  = $condTempo;
            $tipoTransporte = !empty($_POST['tipoTransporte']) ? trim($_POST['tipoTransporte']) : 'stco';
            $tipoContagem   = !empty($_POST['tipoContagem']) ? trim($_POST['tipoContagem']) : 'percentual';
            $tipoOperacao   = !empty($_POST['tipoOperacao']) ? trim($_POST['tipoOperacao']) : 'embarque';
            $turno          = !empty($_POST['turno']) ? trim($_POST['turno']) : 'Matutino';
            $veiculosSel    = !empty($_POST['veiculosSelecionados']) ? trim((string)$_POST['veiculosSelecionados']) : '';
            if (empty($veiculosSel) && $idVeiculo) {
                $veiculosSel = (string)$idVeiculo;
            }

            if (in_array(strtolower($tipoOperacao), ['ida', 'volta'])) {
                $sentido = ucfirst(strtolower($tipoOperacao));
            }

            // Cancela/Finaliza qualquer contagem ativa anterior do mesmo usuário
            $stmtCloseOld = $pdo->prepare("UPDATE pesquisa SET status = 'concluida', dataFim = NOW() WHERE idUsuario = ? AND status = 'em_andamento'");
            $stmtCloseOld->execute([$usuarioId]);

            // Mapeamentos para enums da tabela pesquisa
            $viagemEnum = (strcasecmp($sentido, 'Volta') === 0) ? 'Volta' : 'Ida';
            $tipoContagemEnum = (stripos($tipoContagem, 'perc') !== false) ? 'Percentual' : 'Quantidade';
            $tipoOperacaoEnum = 'Embarque';
            if (stripos($tipoOperacao, 'desemb') !== false) $tipoOperacaoEnum = 'Desembarque';
            elseif (stripos($tipoOperacao, 'ocup') !== false) $tipoOperacaoEnum = 'Ocupação';

            $condTempoEnum = 'Bom';
            if (stripos($condTempo, 'nubl') !== false) $condTempoEnum = 'Nublado';
            elseif (stripos($condTempo, 'leve') !== false) $condTempoEnum = 'Chuva Leve';
            elseif (stripos($condTempo, 'dens') !== false || stripos($condTempo, 'fort') !== false) $condTempoEnum = 'Chuva Densa';

            // Obter idSistema correspondente
            $idSistema = 5; // default STCO
            try {
                $stmtSis = $pdo->prepare("SELECT idsistemas FROM sistema WHERE LOWER(nomeSistema) = LOWER(?) LIMIT 1");
                $stmtSis->execute([$tipoTransporte]);
                $sisVal = $stmtSis->fetchColumn();
                if ($sisVal) $idSistema = (int)$sisVal;
            } catch (\Exception $eSis) {}

            // Cria nova pesquisa com todos os dados informados
            $stmtNew = $pdo->prepare("
                INSERT INTO pesquisa 
                    (idUsuario, idLinha, idVeiculo, idLocal, viagem, condTempo, idSistema, tipoTransporte, tipoContagem, tipoOperacao, turno, status, totalPassageiros, dataInicio) 
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'em_andamento', 0, NOW())
            ");
            $stmtNew->execute([$usuarioId, $idLinha, $idVeiculo, $idLocal, $viagemEnum, $condTempoEnum, $idSistema, $tipoTransporte, $tipoContagemEnum, $tipoOperacaoEnum, $turno]);
            $idPesquisa = (int)$pdo->lastInsertId();

            // Inserir os veículos selecionados na tabela veiculoPesquisado
            $veicArray = array_values(array_filter(array_map('intval', explode(',', $veiculosSel))));
            if ($idVeiculo && !in_array((int)$idVeiculo, $veicArray)) {
                $veicArray[] = (int)$idVeiculo;
            }
            if (!empty($veicArray)) {
                $stmtVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                foreach ($veicArray as $vId) {
                    if ($vId > 0) {
                        try {
                            $stmtVP->execute([$idPesquisa, $vId]);
                        } catch (\Exception $exVP) {}
                    }
                }
            }

            if ($isAjax) {
                echo json_encode(['success' => true, 'message' => 'Contagem iniciada com sucesso!', 'idContagem' => $idPesquisa]);
                exit;
            }
            $mensagemSucesso = "Contagem de passageiros iniciada com sucesso!";
        }

        // 2. Incrementar contagem (+1, +5, +10 e porcentagens)
        elseif ($action === 'incrementar') {
            $idContagem    = (int)$_POST['idContagem']; // idPesquisa
            $qtd           = isset($_POST['qtd']) ? (int)$_POST['qtd'] : -1;
            $modo          = isset($_POST['modo']) ? trim($_POST['modo']) : '';
            $idVeiculoAlvo = !empty($_POST['idVeiculo']) ? (int)$_POST['idVeiculo'] : 0;

            if ($qtd >= 0 && $idContagem > 0) {
                // Valida pertencimento da pesquisa ativa
                $stmtCheck = $pdo->prepare("SELECT idPesquisa, totalPassageiros, tipoContagem, idVeiculo FROM pesquisa WHERE idPesquisa = ? AND idUsuario = ? AND status = 'em_andamento' LIMIT 1");
                $stmtCheck->execute([$idContagem, $usuarioId]);
                $contagemAtiva = $stmtCheck->fetch();

                if ($contagemAtiva) {
                    $isPercentual = (stripos($contagemAtiva['tipoContagem'], 'perc') !== false || $modo === 'substituir');

                    if ($isPercentual) {
                        // Na contagem percentual, SUBSTITUI o valor da ocupação
                        $stmtUpd = $pdo->prepare("UPDATE pesquisa SET totalPassageiros = ? WHERE idPesquisa = ?");
                        $stmtUpd->execute([$qtd, $idContagem]);
                        $novoTotal = $qtd;
                    } else {
                        // Na contagem numérica (+1, +5, +10), SOMA ao total acumulado
                        if ($qtd > 0) {
                            $stmtUpd = $pdo->prepare("UPDATE pesquisa SET totalPassageiros = totalPassageiros + ? WHERE idPesquisa = ?");
                            $stmtUpd->execute([$qtd, $idContagem]);
                        }
                        $novoTotal = (int)$contagemAtiva['totalPassageiros'] + $qtd;
                    }

                    // Se foi especificado veículo alvo, atualiza idVeiculo ativo da pesquisa
                    $veicIdContar = ($idVeiculoAlvo > 0) ? $idVeiculoAlvo : (int)$contagemAtiva['idVeiculo'];
                    if ($idVeiculoAlvo > 0 && $idVeiculoAlvo != (int)$contagemAtiva['idVeiculo']) {
                        $stmtVeicAtv = $pdo->prepare("UPDATE pesquisa SET idVeiculo = ? WHERE idPesquisa = ?");
                        $stmtVeicAtv->execute([$idVeiculoAlvo, $idContagem]);
                    }

                    // Localizar ou registrar idVeiculoPesquisado
                    $idVP = null;
                    if ($veicIdContar > 0) {
                        $stmtVP = $pdo->prepare("SELECT idVeiculoPesquisado FROM veiculoPesquisado WHERE idPesquisa = ? AND idVeiculo = ? LIMIT 1");
                        $stmtVP->execute([$idContagem, $veicIdContar]);
                        $idVP = $stmtVP->fetchColumn();
                        if (!$idVP) {
                            try {
                                $stmtInsVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                                $stmtInsVP->execute([$idContagem, $veicIdContar]);
                                $idVP = $pdo->lastInsertId();
                            } catch (\Exception $exInsVP) {}
                        }
                    }
                    // Registrar na tabela contagem apenas se houver veículo selecionado
                    if ($qtd > 0 && $idVP) {
                        try {
                            $stmtContIns = $pdo->prepare("INSERT INTO contagem (idPesquisa, idVeiculoPesquisado, quantidade, idUsuario, tipo, dataHora) VALUES (?, ?, ?, ?, 'incremento', NOW())");
                            $stmtContIns->execute([$idContagem, $idVP, $qtd, $usuarioId]);
                        } catch (\Exception $exCont) {}
                    }

                    if ($isAjax) {
                        // Busca registros da sessão (apenas salvamentos e correções)
                        $stmtLogs = $pdo->prepare("
                            SELECT c.*, c.idContagem as idRegistro, DATE_FORMAT(c.dataHora, '%H:%i:%s') as horaFormatada 
                            FROM contagem c 
                            WHERE c.idPesquisa = ? AND c.tipo != 'incremento' 
                            ORDER BY c.idContagem DESC LIMIT 10
                        ");
                        $stmtLogs->execute([$idContagem]);
                        $logs = $stmtLogs->fetchAll();

                        echo json_encode([
                            'success' => true, 
                            'total' => $novoTotal, 
                            'logs' => $logs
                        ]);
                        exit;
                    }
                }
            }
        }

        // 3. Corrigir / Abater contagem com Justificativa Obrigatória
        elseif ($action === 'corrigir') {
            $idContagem    = (int)$_POST['idContagem'];
            $qtdSubtrair   = (int)$_POST['qtd_subtrair'];
            $justificativa = trim($_POST['justificativa']);

            if ($idContagem > 0 && $qtdSubtrair > 0) {
                if (empty($justificativa) || strlen($justificativa) < 3) {
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => 'Por favor, informe uma justificativa válida para a correção.']);
                        exit;
                    }
                    $mensagemErro = "A justificativa da correção é obrigatória!";
                } else {
                    $stmtCheck = $pdo->prepare("SELECT idPesquisa, totalPassageiros, idVeiculo FROM pesquisa WHERE idPesquisa = ? AND idUsuario = ? AND status = 'em_andamento' LIMIT 1");
                    $stmtCheck->execute([$idContagem, $usuarioId]);
                    $contagemAtiva = $stmtCheck->fetch();

                    if ($contagemAtiva) {
                        $qtdNegativa = -abs($qtdSubtrair);

                        // Localizar ou registrar idVeiculoPesquisado
                        $idVP = null;
                        if (!empty($contagemAtiva['idVeiculo'])) {
                            $stmtVP = $pdo->prepare("SELECT idVeiculoPesquisado FROM veiculoPesquisado WHERE idPesquisa = ? AND idVeiculo = ? LIMIT 1");
                            $stmtVP->execute([$idContagem, $contagemAtiva['idVeiculo']]);
                            $idVP = $stmtVP->fetchColumn();
                            if (!$idVP) {
                                $stmtInsVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                                $stmtInsVP->execute([$idContagem, $contagemAtiva['idVeiculo']]);
                                $idVP = $pdo->lastInsertId();
                            }
                        }

                        // Registra a correção na tabela contagem
                        if ($idVP) {
                            $stmtReg = $pdo->prepare("INSERT INTO contagem (idPesquisa, idVeiculoPesquisado, idUsuario, tipo, quantidade, justificativa, dataHora) VALUES (?, ?, ?, 'correcao', ?, ?, NOW())");
                            $stmtReg->execute([$idContagem, $idVP, $usuarioId, $qtdNegativa, $justificativa]);
                        }

                        // Atualiza o total (evitando total negativo)
                        $novoTotal = max(0, (int)$contagemAtiva['totalPassageiros'] - $qtdSubtrair);
                        $stmtUpd = $pdo->prepare("UPDATE pesquisa SET totalPassageiros = ? WHERE idPesquisa = ?");
                        $stmtUpd->execute([$novoTotal, $idContagem]);

                        if ($isAjax) {
                            $stmtLogs = $pdo->prepare("
                                SELECT c.*, c.idContagem as idRegistro, DATE_FORMAT(c.dataHora, '%H:%i:%s') as horaFormatada 
                                FROM contagem c 
                                WHERE c.idPesquisa = ? AND c.tipo != 'incremento' 
                                ORDER BY c.idContagem DESC LIMIT 10
                            ");
                            $stmtLogs->execute([$idContagem]);
                            $logs = $stmtLogs->fetchAll();

                            echo json_encode([
                                'success' => true,
                                'total' => $novoTotal,
                                'logs' => $logs,
                                'message' => 'Correção registrada com sucesso!'
                            ]);
                            exit;
                        }
                        $mensagemSucesso = "Correção de -$qtdSubtrair efetuada com sucesso.";
                    }
                }
            }
        }

        // 3.1. Solicitar Correção de Contagem (Enviada ao Supervisor/Gerente pelo Funcionário)
        elseif ($action === 'solicitar_correcao') {
            $idContagem         = (int)$_POST['idContagem'];
            $qtdSubtrair        = (int)$_POST['qtd_subtrair'];
            $justificativa      = isset($_POST['justificativa']) ? trim($_POST['justificativa']) : '';
            $idUsuarioDestino   = isset($_POST['idUsuarioDestino']) ? (int)$_POST['idUsuarioDestino'] : 0;
            $percentualSubtrair = isset($_POST['percentual_subtrair']) ? trim($_POST['percentual_subtrair']) : '';

            if ($idContagem <= 0 || $qtdSubtrair <= 0) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => 'Valor a retirar ou sessão de contagem inválida.']);
                    exit;
                }
                $mensagemErro = 'Valor a retirar ou contagem inválida.';
            } elseif ($idUsuarioDestino <= 0) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => 'Por favor, selecione para qual supervisor/gerente enviar a solicitação.']);
                    exit;
                }
                $mensagemErro = 'Supervisor destinatário não selecionado.';
            } elseif (empty($justificativa) || strlen($justificativa) < 3) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => 'Por favor, informe uma justificativa válida para a correção (mínimo 3 caracteres).']);
                    exit;
                }
                $mensagemErro = 'A justificativa da correção é obrigatória!';
            } else {
                // Valida pesquisa ativa e busca tipoContagem
                $stmtCheck = $pdo->prepare("SELECT idPesquisa as idContagem, tipoContagem FROM pesquisa WHERE idPesquisa = ? AND (idUsuario = ? OR ? = 1) AND status = 'em_andamento' LIMIT 1");
                $stmtCheck->execute([$idContagem, $usuarioId, $loggedAcesso]);
                $contAtiva = $stmtCheck->fetch();

                if (!$contAtiva) {
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => 'Sessão de contagem não localizada ou já finalizada.']);
                        exit;
                    }
                    $mensagemErro = 'Sessão de contagem inválida.';
                } else {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO solicitacoesCorrecao 
                            (idContagem, idUsuarioSolicitante, idUsuarioDestino, quantidade, justificativa, status, dataSolicitacao) 
                        VALUES 
                            (?, ?, ?, ?, ?, 'pendente', NOW())
                    ");
                    $stmtIns->execute([$idContagem, $usuarioId, $idUsuarioDestino, $qtdSubtrair, $justificativa]);

                    $isPerc = (!empty($contAtiva['tipoContagem']) && stripos($contAtiva['tipoContagem'], 'perc') !== false);
                    $textoQtd = $isPerc 
                        ? ($percentualSubtrair ? $percentualSubtrair . '%' : calcPercentualStr($qtdSubtrair))
                        : $qtdSubtrair . ' passageiro(s)';

                    if ($isAjax) {
                        echo json_encode([
                            'success' => true,
                            'message' => 'Solicitação de correção de -' . $textoQtd . ' enviada com sucesso ao supervisor!'
                        ]);
                        exit;
                    }
                    $mensagemSucesso = "Solicitação de correção enviada com sucesso ao supervisor!";
                }
            }
        }

        // 3.2. Responder Solicitação de Correção (Aprovar / Rejeitar por Supervisor/Gerente/Admin)
        elseif ($action === 'responder_solicitacao') {
            $idSolicitacao = isset($_POST['idSolicitacao']) ? (int)$_POST['idSolicitacao'] : 0;
            $decisao       = isset($_POST['decisao']) ? trim(strtolower($_POST['decisao'])) : ''; // 'aprovar' ou 'rejeitar'
            $motivo        = isset($_POST['motivo']) ? trim($_POST['motivo']) : '';

            if ($idSolicitacao <= 0 || !in_array($decisao, ['aprovar', 'rejeitar'])) {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => 'Parâmetros de resposta inválidos.']);
                    exit;
                }
                $mensagemErro = 'Parâmetros de resposta inválidos.';
            } else {
                // Busca a solicitação pendente com dados da pesquisa
                $stmtSol = $pdo->prepare("
                    SELECT s.*, p.totalPassageiros, p.tipoContagem, p.status as contagemStatus, p.idVeiculo
                    FROM solicitacoesCorrecao s
                    LEFT JOIN pesquisa p ON s.idContagem = p.idPesquisa
                    WHERE s.idSolicitacao = ? AND s.status = 'pendente'
                    LIMIT 1
                ");
                $stmtSol->execute([$idSolicitacao]);
                $sol = $stmtSol->fetch();

                if (!$sol) {
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => 'Solicitação não encontrada ou já respondida anteriormente.']);
                        exit;
                    }
                    $mensagemErro = 'Solicitação não encontrada ou já respondida.';
                } elseif (!$canViewSolicitacoes) {
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => 'Acesso negado: apenas administradores e gerentes podem responder a solicitações.']);
                        exit;
                    }
                    $mensagemErro = 'Apenas administradores e gerentes podem responder a solicitações.';
                } elseif (!$isAdmin && !$isGerente && (int)$sol['idUsuarioDestino'] !== $usuarioId) {
                    if ($isAjax) {
                        echo json_encode(['success' => false, 'message' => 'Apenas o supervisor/gerente destinatário desta solicitação ou um administrador pode respondê-la.']);
                        exit;
                    }
                    $mensagemErro = 'Permissão negada para responder a esta solicitação.';
                } else {
                    if ($decisao === 'aprovar') {
                        $qtdSubtrair = (int)$sol['quantidade'];
                        $idContagem  = (int)$sol['idContagem'];
                        $novoTotal   = max(0, (int)$sol['totalPassageiros'] - $qtdSubtrair);

                        // Atualiza total da pesquisa
                        $stmtUpdC = $pdo->prepare("UPDATE pesquisa SET totalPassageiros = ? WHERE idPesquisa = ?");
                        $stmtUpdC->execute([$novoTotal, $idContagem]);

                        // Localizar idVeiculoPesquisado
                        $idVP = null;
                        if (!empty($sol['idVeiculo'])) {
                            $stmtVP = $pdo->prepare("SELECT idVeiculoPesquisado FROM veiculoPesquisado WHERE idPesquisa = ? AND idVeiculo = ? LIMIT 1");
                            $stmtVP->execute([$idContagem, $sol['idVeiculo']]);
                            $idVP = $stmtVP->fetchColumn();
                            if (!$idVP) {
                                $stmtInsVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                                $stmtInsVP->execute([$idContagem, $sol['idVeiculo']]);
                                $idVP = $pdo->lastInsertId();
                            }
                        }

                        // Registra no extrato da contagem
                        $isPerc = (!empty($sol['tipoContagem']) && stripos($sol['tipoContagem'], 'perc') !== false);
                        $descAjuste = $isPerc 
                            ? calcPercentualStr($qtdSubtrair) . " (-{$qtdSubtrair} pas.)"
                            : "-{$qtdSubtrair} passageiro(s)";

                        $justifReg = "Correção de {$descAjuste} aprovada por {$loggedNome}. Motivo: " . $sol['justificativa'];
                        if ($idVP) {
                            $stmtReg = $pdo->prepare("INSERT INTO contagem (idPesquisa, idVeiculoPesquisado, idUsuario, tipo, quantidade, justificativa, dataHora) VALUES (?, ?, ?, 'correcao', ?, ?, NOW())");
                            $stmtReg->execute([$idContagem, $idVP, $sol['idUsuarioSolicitante'], -$qtdSubtrair, $justifReg]);
                        }

                        // Marca solicitação como aprovada
                        $stmtUpdSol = $pdo->prepare("
                            UPDATE solicitacoesCorrecao 
                            SET status = 'aprovada', idUsuarioResposta = ?, motivoResposta = ?, dataResposta = NOW() 
                            WHERE idSolicitacao = ?
                        ");
                        $stmtUpdSol->execute([$usuarioId, $motivo ?: 'Aprovada pelo supervisor.', $idSolicitacao]);

                        if ($isAjax) {
                            echo json_encode([
                                'success' => true,
                                'message' => "Solicitação #{$idSolicitacao} aprovada com sucesso! Ajuste de {$descAjuste} aplicado à contagem #{$idContagem}."
                            ]);
                            exit;
                        }
                        $mensagemSucesso = "Solicitação aprovada e ajuste efetuado com sucesso!";
                    } else {
                        // Rejeitar
                        $motivoFinal = !empty($motivo) ? $motivo : 'Solicitação recusada pelo supervisor.';

                        $stmtUpdSol = $pdo->prepare("
                            UPDATE solicitacoesCorrecao 
                            SET status = 'rejeitada', idUsuarioResposta = ?, motivoResposta = ?, dataResposta = NOW() 
                            WHERE idSolicitacao = ?
                        ");
                        $stmtUpdSol->execute([$usuarioId, $motivoFinal, $idSolicitacao]);

                        if ($isAjax) {
                            echo json_encode([
                                'success' => true,
                                'message' => "Solicitação #{$idSolicitacao} recusada pelo supervisor."
                            ]);
                            exit;
                        }
                        $mensagemSucesso = "Solicitação recusada com sucesso.";
                    }
                }
            }
        }

        // 4. Finalizar Sessão de Contagem
        elseif ($action === 'finalizar_contagem') {
            $idContagem = (int)$_POST['idContagem'];
            if ($idContagem > 0) {
                $stmtFin = $pdo->prepare("UPDATE pesquisa SET status = 'concluida', dataFim = NOW() WHERE (idPesquisa = ? OR status = 'em_andamento') AND idUsuario = ?");
                $stmtFin->execute([$idContagem, $usuarioId]);

                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => 'Sessão de contagem finalizada com sucesso!']);
                    exit;
                }
                $mensagemSucesso = "Sessão de contagem finalizada e salva com sucesso!";
            }
        }

        // 4.0.1. Alterar Linha, Veículo e Turno da Sessão Ativa
        elseif ($action === 'alterar_linha_veiculo') {
            $idContagem = (int)$_POST['idContagem'];
            $idLinha    = !empty($_POST['idLinha']) ? (int)$_POST['idLinha'] : null;
            $idVeiculo  = !empty($_POST['idVeiculo']) ? (int)$_POST['idVeiculo'] : null;
            $turno      = !empty($_POST['turno']) ? trim($_POST['turno']) : null;

            if ($idContagem > 0) {
                $stmtCheck = $pdo->prepare("SELECT idPesquisa, idLinha, idVeiculo, turno FROM pesquisa WHERE idPesquisa = ? AND idUsuario = ? AND status = 'em_andamento' LIMIT 1");
                $stmtCheck->execute([$idContagem, $usuarioId]);
                $cRow = $stmtCheck->fetch();
                if ($cRow) {
                    $novoTurno   = ($turno !== null && $turno !== '') ? $turno : $cRow['turno'];
                    $novaLinha   = ($idLinha !== null && $idLinha > 0) ? $idLinha : $cRow['idLinha'];
                    $novoVeiculo = ($idVeiculo !== null && $idVeiculo > 0) ? $idVeiculo : $cRow['idVeiculo'];

                    $stmtUpd = $pdo->prepare("UPDATE pesquisa SET idLinha = ?, idVeiculo = ?, turno = ? WHERE idPesquisa = ? AND idUsuario = ?");
                    $stmtUpd->execute([$novaLinha, $novoVeiculo, $novoTurno, $idContagem, $usuarioId]);

                    // Garantir que o novo veículo também esteja vinculado em veiculoPesquisado
                    if ($novoVeiculo > 0) {
                        $stmtChkVP = $pdo->prepare("SELECT idVeiculoPesquisado FROM veiculoPesquisado WHERE idPesquisa = ? AND idVeiculo = ? LIMIT 1");
                        $stmtChkVP->execute([$idContagem, $novoVeiculo]);
                        if (!$stmtChkVP->fetchColumn()) {
                            $stmtInsVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                            $stmtInsVP->execute([$idContagem, $novoVeiculo]);
                        }
                    }

                    if ($isAjax) {
                        $stmtInfo = $pdo->prepare("
                            SELECT p.idLinha, p.idVeiculo, p.turno,
                                   l.numero as linhaNumero, l.descricao as linhaDescricao,
                                   v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca
                            FROM pesquisa p
                            LEFT JOIN linha l ON p.idLinha = l.idLinha
                            LEFT JOIN veiculo v ON p.idVeiculo = v.idVeiculo
                            WHERE p.idPesquisa = ?
                            LIMIT 1
                        ");
                        $stmtInfo->execute([$idContagem]);
                        $infoUpdated = $stmtInfo->fetch();

                        $nL = !empty($infoUpdated['linhaNumero']) ? $infoUpdated['linhaNumero'] . ' - ' . $infoUpdated['linhaDescricao'] : (!empty($infoUpdated['idLinha']) ? 'Linha #'.$infoUpdated['idLinha'] : 'N/A');
                        $veic = !empty($infoUpdated['veiculoPrefixo']) ? $infoUpdated['veiculoPrefixo'] : (!empty($infoUpdated['idVeiculo']) ? 'Prefixo #'.$infoUpdated['idVeiculo'] : 'N/A');
                        $turnText = !empty($infoUpdated['turno']) ? $infoUpdated['turno'] : 'Matutino';

                        echo json_encode([
                            'success' => true,
                            'message' => 'Dados da sessão alterados com sucesso!',
                            'idLinha' => $infoUpdated['idLinha'],
                            'idVeiculo' => $infoUpdated['idVeiculo'],
                            'linhaText' => $nL,
                            'veiculoText' => $veic,
                            'turnoText' => $turnText
                        ]);
                        exit;
                    }
                    $mensagemSucesso = "Dados da sessão alterados com sucesso!";
                }
            }
        }

        // 4.0.1.1. Atualizar Veículos Selecionados da Sessão Ativa
        elseif ($action === 'atualizar_veiculos_sessao') {
            $idContagem  = (int)$_POST['idContagem'];
            $veiculosSel = isset($_POST['veiculosSelecionados']) ? trim((string)$_POST['veiculosSelecionados']) : '';

            if ($idContagem > 0) {
                $idsArr = array_values(array_filter(array_map('intval', explode(',', $veiculosSel))));

                // Sincronizar veículos na tabela veiculoPesquisado
                $stmtExistentes = $pdo->prepare("SELECT idVeiculo FROM veiculoPesquisado WHERE idPesquisa = ?");
                $stmtExistentes->execute([$idContagem]);
                $existentesArr = $stmtExistentes->fetchAll(PDO::FETCH_COLUMN);

                $stmtInsVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                foreach ($idsArr as $vId) {
                    if (!in_array($vId, $existentesArr)) {
                        try {
                            $stmtInsVP->execute([$idContagem, $vId]);
                        } catch (\Exception $exVP) {}
                    }
                }

                $veiculosDados = [];
                if (!empty($idsArr)) {
                    $inClause = implode(',', array_fill(0, count($idsArr), '?'));
                    $stmtBuscaV = $pdo->prepare("SELECT idVeiculo, prefixo, placa FROM veiculo WHERE idVeiculo IN ($inClause) ORDER BY prefixo");
                    $stmtBuscaV->execute($idsArr);
                    $veiculosDados = $stmtBuscaV->fetchAll();
                }

                if ($isAjax) {
                    echo json_encode([
                        'success'  => true,
                        'message'  => 'Veículos da contagem atualizados com sucesso!',
                        'veiculos' => $veiculosDados
                    ]);
                    exit;
                }
            }
        }

        // 4.0.1.2. Cadastrar Novo Veículo na Frota
        elseif ($action === 'cadastrar_veiculo_frota_rapido') {
            $prefixo = isset($_POST['prefixo']) ? trim((string)$_POST['prefixo']) : '';
            $placa   = isset($_POST['placa']) ? trim((string)$_POST['placa']) : '';

            if ($prefixo === '') {
                if ($isAjax) {
                    echo json_encode(['success' => false, 'message' => 'Prefixo do veículo é obrigatório.']);
                    exit;
                }
            } else {
                $stmtCheckV = $pdo->prepare("SELECT idVeiculo, prefixo, placa, ativo FROM veiculo WHERE LOWER(TRIM(prefixo)) = LOWER(TRIM(?)) LIMIT 1");
                $stmtCheckV->execute([$prefixo]);
                $vExistente = $stmtCheckV->fetch();

                if ($vExistente) {
                    $idVeicReal = (int)$vExistente['idVeiculo'];
                    $prefReal   = $vExistente['prefixo'];
                    $placaReal  = !empty($vExistente['placa']) ? $vExistente['placa'] : $placa;

                    if (isset($vExistente['ativo']) && (int)$vExistente['ativo'] === 0) {
                        $stmtReativar = $pdo->prepare("UPDATE veiculo SET ativo = 1 WHERE idVeiculo = ?");
                        $stmtReativar->execute([$idVeicReal]);
                    }
                } else {
                    $stmtInsV = $pdo->prepare("INSERT INTO veiculo (prefixo, placa, ativo) VALUES (?, ?, 1)");
                    $stmtInsV->execute([$prefixo, $placa]);
                    $idVeicReal = (int)$pdo->lastInsertId();
                    $prefReal   = $prefixo;
                    $placaReal  = $placa;
                }

                if ($isAjax) {
                    echo json_encode([
                        'success'   => true,
                        'message'   => "Veículo $prefReal registrado com sucesso!",
                        'idVeiculo' => $idVeicReal,
                        'prefixo'   => $prefReal,
                        'placa'     => $placaReal
                    ]);
                    exit;
                }
            }
        }

        // 4.0.2. Alterar Turno de Sessão no Histórico
        elseif ($action === 'alterar_turno_historico') {
            $idContagem = (int)$_POST['idContagem'];
            $turno = !empty($_POST['turno']) ? trim($_POST['turno']) : '';
            $turnosValidos = array('Matutino', 'Vespertino', 'Noturno');

            if ($idContagem > 0 && in_array($turno, $turnosValidos)) {
                if ($podeVerSolicitacoes) {
                    $stmtUpd = $pdo->prepare("UPDATE pesquisa SET turno = ? WHERE idPesquisa = ?");
                    $stmtUpd->execute(array($turno, $idContagem));
                } else {
                    $stmtUpd = $pdo->prepare("UPDATE pesquisa SET turno = ? WHERE idPesquisa = ? AND idUsuario = ?");
                    $stmtUpd->execute(array($turno, $idContagem, $usuarioId));
                }

                echo json_encode(array(
                    'success' => true,
                    'message' => 'Turno atualizado com sucesso!',
                    'turno' => $turno
                ));
                exit;
            }

            echo json_encode(array('success' => false, 'message' => 'Turno inválido ou sessão não encontrada.'));
            exit;
        }

        // 4.0. Salvar Progresso da Contagem (Mantém Sessão Ativa)
        elseif ($action === 'salvar_contagem') {
            $idContagem = (int)$_POST['idContagem'];
            if ($idContagem > 0) {
                $stmtUpd = $pdo->prepare("UPDATE pesquisa SET dataFim = NOW() WHERE idPesquisa = ? AND idUsuario = ? AND status = 'em_andamento'");
                $stmtUpd->execute([$idContagem, $usuarioId]);

                // Busca dados completos da pesquisa com relacionamentos
                $stmtInfo = $pdo->prepare("
                    SELECT p.*, p.idPesquisa as idContagem, p.viagem as sentido, p.condTempo as condicaoTempo,
                           l.numero as linhaNumero, l.descricao as linhaDescricao,
                           v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca,
                           loc.local as nomeLocal
                    FROM pesquisa p
                    LEFT JOIN linha l ON p.idLinha = l.idLinha
                    LEFT JOIN veiculo v ON p.idVeiculo = v.idVeiculo
                    LEFT JOIN locais loc ON p.idLocal = loc.idLocal
                    WHERE p.idPesquisa = ? AND p.idUsuario = ?
                    LIMIT 1
                ");
                $stmtInfo->execute([$idContagem, $usuarioId]);
                $cData = $stmtInfo->fetch();

                if ($cData) {
                    if (empty($cData['idVeiculo'])) {
                        if ($isAjax) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'Por favor, selecione um ônibus antes de salvar a contagem.'
                            ]);
                            exit;
                        }
                    } else {
                        $totalVal = (float)$cData['totalPassageiros'];
                        $msgSalvamento = "Contagem salva com sucesso";

                        // Localizar ou inserir idVeiculoPesquisado
                        $idVP = null;
                        $stmtVP = $pdo->prepare("SELECT idVeiculoPesquisado FROM veiculoPesquisado WHERE idPesquisa = ? AND idVeiculo = ? LIMIT 1");
                        $stmtVP->execute([$idContagem, $cData['idVeiculo']]);
                        $idVP = $stmtVP->fetchColumn();
                        if (!$idVP) {
                            try {
                                $stmtInsVP = $pdo->prepare("INSERT INTO veiculoPesquisado (idPesquisa, idVeiculo) VALUES (?, ?)");
                                $stmtInsVP->execute([$idContagem, $cData['idVeiculo']]);
                                $idVP = $pdo->lastInsertId();
                            } catch (\Exception $exVP) {}
                        }

                        // Registra salvamento na tabela contagem apenas quando o veículo estiver selecionado
                        if ($idVP) {
                            try {
                                $stmtReg = $pdo->prepare("INSERT INTO contagem (idPesquisa, idVeiculoPesquisado, idUsuario, tipo, quantidade, justificativa, dataHora) VALUES (?, ?, ?, 'salvamento', ?, ?, NOW())");
                                $stmtReg->execute([$idContagem, $idVP, $usuarioId, $totalVal, $msgSalvamento]);
                            } catch (\Exception $exReg) {
                                error_log("Erro ao registrar salvamento: " . $exReg->getMessage());
                            }
                        }
                    }
                }

                // Busca registros da sessão (apenas salvamentos e correções)
                $stmtLogs = $pdo->prepare("
                    SELECT c.*, c.idContagem as idRegistro, DATE_FORMAT(c.dataHora, '%H:%i:%s') as horaFormatada 
                    FROM contagem c 
                    WHERE c.idPesquisa = ? AND c.tipo != 'incremento' 
                    ORDER BY c.idContagem DESC LIMIT 10
                ");
                $stmtLogs->execute([$idContagem]);
                $logs = $stmtLogs->fetchAll();

                if ($isAjax) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Contagem salva com sucesso! A sessão permanece aberta para continuar os lançamentos.',
                        'logs' => $logs
                    ]);
                    exit;
                }
                $mensagemSucesso = "Contagem salva com sucesso! A sessão permanece aberta para continuar os lançamentos.";
            }
        }

        // 4.1. Cancelar Sessão de Contagem
        elseif ($action === 'cancelar_contagem') {
            $idContagem = (int)$_POST['idContagem'];
            if ($idContagem > 0) {
                try {
                    $stmtCanc = $pdo->prepare("UPDATE pesquisa SET status = 'cancelada', dataFim = NOW() WHERE (idPesquisa = ? OR status = 'em_andamento') AND idUsuario = ?");
                    $stmtCanc->execute([$idContagem, $usuarioId]);
                } catch (\Exception $ex) {
                    $stmtCanc = $pdo->prepare("DELETE FROM pesquisa WHERE (idPesquisa = ? OR status = 'em_andamento') AND idUsuario = ?");
                    $stmtCanc->execute([$idContagem, $usuarioId]);
                }

                if ($isAjax) {
                    echo json_encode(['success' => true, 'message' => 'Contagem cancelada com sucesso.']);
                    exit;
                }
                $mensagemSucesso = "Sessão de contagem cancelada com sucesso.";
            }
        }

        // 5. Obter Detalhes/Extrato Completo de uma Contagem Anterior (AJAX)
        elseif ($action === 'obter_detalhes_historico') {
            $idContagem = (int)$_POST['idContagem'];
            if ($idContagem > 0) {
                $stmtC = $pdo->prepare("
                    SELECT p.*, p.idPesquisa as idContagem, p.viagem as sentido, p.condTempo as condicaoTempo,
                           DATE_FORMAT(p.dataInicio, '%d/%m/%Y %H:%i:%s') as inicioFmt,
                           DATE_FORMAT(p.dataFim, '%d/%m/%Y %H:%i:%s') as fimFmt,
                           l.numero as linhaNumero, l.descricao as linhaDescricao,
                           v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca,
                           loc.local as nomeLocal
                    FROM pesquisa p
                    LEFT JOIN linha l ON p.idLinha = l.idLinha
                    LEFT JOIN veiculo v ON p.idVeiculo = v.idVeiculo
                    LEFT JOIN locais loc ON p.idLocal = loc.idLocal
                    WHERE p.idPesquisa = ? AND p.idUsuario = ? AND LOWER(COALESCE(p.status, '')) != 'cancelada'
                    LIMIT 1
                ");
                $stmtC->execute([$idContagem, $usuarioId]);
                $contagem = $stmtC->fetch();

                if ($contagem) {
                    $stmtRegs = $pdo->prepare("
                        SELECT c.*, c.idContagem as idRegistro, DATE_FORMAT(c.dataHora, '%d/%m/%Y %H:%i:%s') as dataHoraFmt,
                               v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca
                        FROM contagem c 
                        LEFT JOIN veiculoPesquisado vp ON c.idVeiculoPesquisado = vp.idVeiculoPesquisado
                        LEFT JOIN veiculo v ON vp.idVeiculo = v.idVeiculo
                        WHERE c.idPesquisa = ? 
                        ORDER BY c.idContagem DESC
                    ");
                    $stmtRegs->execute([$idContagem]);
                    $registros = $stmtRegs->fetchAll();

                    echo json_encode([
                        'success' => true,
                        'contagem' => $contagem,
                        'registros' => $registros
                    ]);
                    exit;
                }
            }
            echo json_encode(['success' => false, 'message' => 'Registro de contagem não localizado.']);
            exit;
        }

    } catch (Exception $ex) {
        if ($isAjax) {
            echo json_encode(['success' => false, 'message' => 'Erro ao processar: ' . $ex->getMessage()]);
            exit;
        }
        $mensagemErro = "Erro ao processar ação: " . $ex->getMessage();
    }
}

// ==========================================
// CARREGAMENTO DE DADOS DA SESSÃO ATIVA E HISTÓRICO
// ==========================================

// Buscar sessão de contagem em andamento para o usuário logado
$sessaoAtiva = null;
try {
    $stmtSessao = $pdo->prepare("
        SELECT p.*, p.idPesquisa as idContagem, p.viagem as sentido, p.condTempo as condicaoTempo,
               DATE_FORMAT(p.dataInicio, '%d/%m/%Y %H:%i') as dataInicioFormatada,
               l.numero as linhaNumero, l.descricao as linhaDescricao,
               v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca,
               loc.local as nomeLocal
        FROM pesquisa p
        LEFT JOIN linha l ON p.idLinha = l.idLinha
        LEFT JOIN veiculo v ON p.idVeiculo = v.idVeiculo
        LEFT JOIN locais loc ON p.idLocal = loc.idLocal
        WHERE p.idUsuario = ? AND p.status = 'em_andamento'
        ORDER BY p.idPesquisa DESC LIMIT 1
    ");
    $stmtSessao->execute([$usuarioId]);
    $sessaoAtiva = $stmtSessao->fetch();

    if ($sessaoAtiva) {
        $stmtVPs = $pdo->prepare("SELECT idVeiculo FROM veiculoPesquisado WHERE idPesquisa = ?");
        $stmtVPs->execute([$sessaoAtiva['idPesquisa']]);
        $idsVeicsFromVP = $stmtVPs->fetchAll(PDO::FETCH_COLUMN);
        if ($sessaoAtiva['idVeiculo'] && !in_array($sessaoAtiva['idVeiculo'], $idsVeicsFromVP)) {
            $idsVeicsFromVP[] = (int)$sessaoAtiva['idVeiculo'];
        }
        $sessaoAtiva['veiculosSelecionados'] = implode(',', $idsVeicsFromVP);
    }
} catch (PDOException $e) {}

// Histórico de lançamentos da sessão ativa (apenas salvamentos e correções)
$registrosSessao = [];
if ($sessaoAtiva) {
    try {
        $stmtLogs = $pdo->prepare("
            SELECT c.*, c.idContagem as idRegistro, DATE_FORMAT(c.dataHora, '%H:%i:%s') as horaFormatada,
                   v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca
            FROM contagem c 
            LEFT JOIN veiculoPesquisado vp ON c.idVeiculoPesquisado = vp.idVeiculoPesquisado
            LEFT JOIN veiculo v ON vp.idVeiculo = v.idVeiculo
            WHERE c.idPesquisa = ? AND c.tipo != 'incremento'
            ORDER BY c.idContagem DESC LIMIT 15
        ");
        $stmtLogs->execute([$sessaoAtiva['idContagem']]);
        $registrosSessao = $stmtLogs->fetchAll();
    } catch (PDOException $e) {}
}

// Carregar empresas disponíveis para os filtros
$empresasDisponiveis = [];
try {
    $targetEmpTable = 'empresas';
    $checkEmpSingular = $pdo->query("SHOW TABLES LIKE 'empresa'");
    if ($checkEmpSingular && count($checkEmpSingular->fetchAll()) > 0) {
        $targetEmpTable = 'empresa';
    }
    $stmtEmp = $pdo->query("SELECT * FROM `$targetEmpTable` ORDER BY nome");
    if ($stmtEmp) $empresasDisponiveis = $stmtEmp->fetchAll();
} catch (\Exception $e) {}

// Parâmetros de Filtro GET do Histórico e Dashboard
$filtroDataInicio = !empty($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
$filtroDataFim    = !empty($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
$filtroHoraInicio = !empty($_GET['hora_inicio']) ? trim($_GET['hora_inicio']) : '';
$filtroHoraFim    = !empty($_GET['hora_fim']) ? trim($_GET['hora_fim']) : '';
$filtroEmpresa    = !empty($_GET['idEmpresa']) ? (int)$_GET['idEmpresa'] : 0;
$filtroLinha      = !empty($_GET['idLinha']) ? (int)$_GET['idLinha'] : 0;
$filtroVeiculo    = !empty($_GET['idVeiculo']) ? (int)$_GET['idVeiculo'] : 0;
$filtroLocal      = !empty($_GET['idLocal']) ? (int)$_GET['idLocal'] : 0;
$filtroTipoOp     = !empty($_GET['tipoOperacao']) ? trim($_GET['tipoOperacao']) : '';

// Buscar Histórico Geral de Contagens do Usuário Logado
$historicoUsuario = [];
$totalSessoesUsuario = 0;
$totalPassageirosUsuario = 0;
$totalCorrecoesUsuario = 0;

$totalEmbarquesPeriodo = 0;
$totalDesembarquesPeriodo = 0;
$passageirosTotaisPeriodo = 0;
$somaOcupacaoPercent = 0;
$qtdPercentContagens = 0;

try {
    $whereClauses = [];
    $paramsHist   = [];

    // Desconsidera contagens canceladas do Histórico, KPIs e Dashboard
    $whereClauses[] = "LOWER(COALESCE(c.status, '')) != 'cancelada'";

    if (!$isAdmin) {
        $whereClauses[] = "c.idUsuario = ?";
        $paramsHist[]   = $usuarioId;
    } else {
        $whereClauses[] = "1=1";
    }

    if ($filtroDataInicio !== '') {
        $whereClauses[] = "DATE(c.dataInicio) >= ?";
        $paramsHist[]   = $filtroDataInicio;
    }
    if ($filtroDataFim !== '') {
        $whereClauses[] = "DATE(c.dataInicio) <= ?";
        $paramsHist[]   = $filtroDataFim;
    }
    if ($filtroHoraInicio !== '') {
        $whereClauses[] = "TIME(c.dataInicio) >= ?";
        $paramsHist[]   = $filtroHoraInicio . ':00';
    }
    if ($filtroHoraFim !== '') {
        $whereClauses[] = "TIME(c.dataInicio) <= ?";
        $paramsHist[]   = $filtroHoraFim . ':59';
    }
    if ($filtroLinha > 0) {
        $whereClauses[] = "c.idLinha = ?";
        $paramsHist[]   = $filtroLinha;
    }
    if ($filtroVeiculo > 0) {
        $whereClauses[] = "c.idVeiculo = ?";
        $paramsHist[]   = $filtroVeiculo;
    }
    if ($filtroLocal > 0) {
        $whereClauses[] = "c.idLocal = ?";
        $paramsHist[]   = $filtroLocal;
    }
    if ($filtroEmpresa > 0) {
        $whereClauses[] = "(c.idEmpresa = ? OR v.idEmpresa = ? OR l.idEmpresa = ?)";
        $paramsHist[]   = $filtroEmpresa;
        $paramsHist[]   = $filtroEmpresa;
        $paramsHist[]   = $filtroEmpresa;
    }
    if ($filtroTipoOp !== '') {
        $opLower = strtolower($filtroTipoOp);
        if ($opLower === 'ida' || $opLower === 'volta') {
            $whereClauses[] = "(LOWER(COALESCE(c.viagem, '')) = ? OR LOWER(COALESCE(c.tipoOperacao, '')) = ?)";
            $paramsHist[]   = $opLower;
            $paramsHist[]   = $opLower;
        } else {
            $whereClauses[] = "LOWER(COALESCE(c.tipoOperacao, '')) = ?";
            $paramsHist[]   = $opLower;
        }
    }

    $whereSql = implode(" AND ", $whereClauses);

    $stmtHist = $pdo->prepare("
        SELECT c.*, c.idPesquisa as idContagem, c.viagem as sentido, c.condTempo as condicaoTempo,
               DATE_FORMAT(c.dataInicio, '%d/%m/%Y %H:%i') as dataInicioFormatada,
               DATE_FORMAT(c.dataFim, '%d/%m/%Y %H:%i') as dataFimFormatada,
               l.numero as linhaNumero, l.descricao as linhaDescricao,
               v.prefixo as veiculoPrefixo, v.placa as veiculoPlaca,
               loc.local as nomeLocal,
               (SELECT COUNT(*) FROM contagem r WHERE r.idPesquisa = c.idPesquisa) as totalLancamentos
        FROM pesquisa c
        LEFT JOIN linha l ON c.idLinha = l.idLinha
        LEFT JOIN veiculo v ON c.idVeiculo = v.idVeiculo
        LEFT JOIN locais loc ON c.idLocal = loc.idLocal
        WHERE $whereSql
        ORDER BY c.idPesquisa DESC
    ");
    $stmtHist->execute($paramsHist);
    $historicoUsuario = $stmtHist->fetchAll();

    foreach ($historicoUsuario as $h) {
        $totalSessoesUsuario++;
        $tot = (int)$h['totalPassageiros'];
        $totalPassageirosUsuario += $tot;
        $passageirosTotaisPeriodo += $tot;

        $op = isset($h['tipoOperacao']) ? strtolower($h['tipoOperacao']) : 'embarque';
        if (in_array($op, ['desembarque', 'volta'])) {
            $totalDesembarquesPeriodo += $tot;
        } elseif (in_array($op, ['embarque', 'ida'])) {
            $totalEmbarquesPeriodo += $tot;
        }

        // Cálculo de Ocupação %
        if ($tot >= 100) $somaOcupacaoPercent += 120;
        elseif ($tot >= 82) $somaOcupacaoPercent += 100;
        elseif ($tot >= 54) $somaOcupacaoPercent += 65;
        elseif ($tot >= 42) $somaOcupacaoPercent += 50;
        elseif ($tot >= 30) $somaOcupacaoPercent += 35;
        elseif ($tot >= 13) $somaOcupacaoPercent += 15;
        else $somaOcupacaoPercent += 0;

        $qtdPercentContagens++;
    }

    $stmtStatsCorr = $pdo->prepare("SELECT COUNT(*) FROM contagem WHERE idUsuario = ? AND tipo = 'correcao'");
    $stmtStatsCorr->execute([$usuarioId]);
    $totalCorrecoesUsuario = (int)$stmtStatsCorr->fetchColumn();

    // --- HELPER DE OCUPAÇÃO PARA O DASHBOARD ---
    if (!function_exists('cbCalcularOcupacao')) {
        function cbCalcularOcupacao($row) {
            if (!empty($row['ocupacaoInformada']) && (int)$row['ocupacaoInformada'] > 0) {
                return (int)$row['ocupacaoInformada'];
            }
            if (!empty($row['mediaPercentual']) && (float)$row['mediaPercentual'] > 0) {
                return round((float)$row['mediaPercentual']);
            }
            $emb = isset($row['embarques']) ? (int)$row['embarques'] : 0;
            $des = isset($row['desembarques']) ? (int)$row['desembarques'] : 0;
            $saldo = max($emb, $emb - $des);
            if ($saldo >= 100) return 80;
            if ($saldo >= 60) return 70;
            if ($saldo >= 40) return 60;
            if ($saldo >= 20) return 40;
            if ($saldo > 0) return 30;
            return 0;
        }
    }

    // --- CONSULTAS DAS 4 TABELAS DO DASHBOARD ---

    // 1. Quantidade de passageiros por data / hora / linha / veículo
    $dadosTabelaLinhaVeiculoDataHora = [];
    try {
        $stmtT1 = $pdo->prepare("
            SELECT 
                DATE_FORMAT(c.dataInicio, '%d/%m/%Y') as dataFormatada,
                DATE_FORMAT(c.dataInicio, '%H:%i') as horaFormatada,
                COALESCE(NULLIF(l.numero, ''), CONCAT('Linha #', c.idLinha)) as linhaNumero,
                COALESCE(l.descricao, l.linha, '') as linhaDescricao,
                COALESCE(NULLIF(v.prefixo, 0), v.placa, 'S/ Veículo') as veiculoPrefixo,
                COALESCE(NULLIF(c.viagem, ''), 'Ida') as sentido,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('embarque', 'ida') THEN c.totalPassageiros ELSE 0 END) as embarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('desembarque', 'volta') THEN c.totalPassageiros ELSE 0 END) as desembarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) = 'ocupação' OR LOWER(c.tipoOperacao) = 'ocupacao' THEN c.totalPassageiros ELSE 0 END) as ocupacaoInformada,
                AVG(CASE WHEN LOWER(c.tipoContagem) LIKE '%perc%' THEN c.totalPassageiros ELSE NULL END) as mediaPercentual,
                COUNT(c.idPesquisa) as totalRegistros
            FROM pesquisa c
            LEFT JOIN linha l ON c.idLinha = l.idLinha
            LEFT JOIN veiculo v ON c.idVeiculo = v.idVeiculo
            LEFT JOIN locais loc ON c.idLocal = loc.idLocal
            WHERE $whereSql
            GROUP BY DATE(c.dataInicio), DATE_FORMAT(c.dataInicio, '%H:%i'), c.idLinha, c.idVeiculo, l.numero, l.descricao, l.linha, v.prefixo, v.placa, COALESCE(NULLIF(c.viagem, ''), 'Ida')
            ORDER BY c.dataInicio DESC
        ");
        $stmtT1->execute($paramsHist);
        $dadosTabelaLinhaVeiculoDataHora = $stmtT1->fetchAll();
    } catch (PDOException $e) {}

    // 2. Total de passageiros por linha (agrupado por Data e Linha)
    $dadosTabelaTotalPorLinha = [];
    try {
        $stmtT2 = $pdo->prepare("
            SELECT 
                DATE_FORMAT(c.dataInicio, '%d/%m/%Y') as dataFormatada,
                c.idLinha,
                COALESCE(NULLIF(l.numero, ''), CONCAT('Linha #', c.idLinha)) as linhaNumero,
                COALESCE(l.descricao, l.linha, '') as linhaDescricao,
                COALESCE(NULLIF(c.viagem, ''), 'Ida') as sentido,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('embarque', 'ida') THEN c.totalPassageiros ELSE 0 END) as embarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('desembarque', 'volta') THEN c.totalPassageiros ELSE 0 END) as desembarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) = 'ocupação' OR LOWER(c.tipoOperacao) = 'ocupacao' THEN c.totalPassageiros ELSE 0 END) as ocupacaoInformada,
                AVG(CASE WHEN LOWER(c.tipoContagem) LIKE '%perc%' THEN c.totalPassageiros ELSE NULL END) as mediaPercentual,
                COUNT(c.idPesquisa) as totalSessoes
            FROM pesquisa c
            LEFT JOIN linha l ON c.idLinha = l.idLinha
            LEFT JOIN veiculo v ON c.idVeiculo = v.idVeiculo
            WHERE $whereSql
            GROUP BY DATE(c.dataInicio), c.idLinha, l.numero, l.descricao, l.linha, COALESCE(NULLIF(c.viagem, ''), 'Ida')
            ORDER BY DATE(c.dataInicio) DESC, embarques DESC
        ");
        $stmtT2->execute($paramsHist);
        $dadosTabelaTotalPorLinha = $stmtT2->fetchAll();
    } catch (PDOException $e) {}

    // 3. Embarques / Desembarques / Ocupação por Local
    $dadosTabelaPorLocal = [];
    try {
        $stmtT3 = $pdo->prepare("
            SELECT 
                c.idLocal,
                COALESCE(loc.local, 'Praça Central') as nomeLocal,
                loc.regiao as regiaoLocal,
                COALESCE(NULLIF(c.viagem, ''), 'Ida') as sentido,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('embarque', 'ida') THEN c.totalPassageiros ELSE 0 END) as embarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('desembarque', 'volta') THEN c.totalPassageiros ELSE 0 END) as desembarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) = 'ocupação' OR LOWER(c.tipoOperacao) = 'ocupacao' THEN c.totalPassageiros ELSE 0 END) as ocupacaoInformada,
                AVG(CASE WHEN LOWER(c.tipoContagem) LIKE '%perc%' THEN c.totalPassageiros ELSE NULL END) as mediaPercentual,
                COUNT(c.idPesquisa) as totalSessoes
            FROM pesquisa c
            LEFT JOIN locais loc ON c.idLocal = loc.idLocal
            LEFT JOIN veiculo v ON c.idVeiculo = v.idVeiculo
            LEFT JOIN linha l ON c.idLinha = l.idLinha
            WHERE $whereSql
            GROUP BY c.idLocal, loc.local, loc.regiao, COALESCE(NULLIF(c.viagem, ''), 'Ida')
            ORDER BY embarques DESC
        ");
        $stmtT3->execute($paramsHist);
        $dadosTabelaPorLocal = $stmtT3->fetchAll();
    } catch (PDOException $e) {}

    // 4. Embarques / Desembarques / Ocupação por Empresa
    $dadosTabelaPorEmpresa = [];
    try {
        $stmtT4 = $pdo->prepare("
            SELECT 
                COALESCE(e.idEmpresa, 1) as idEmpresa,
                COALESCE(NULLIF(e.nome, ''), NULLIF(e.nomeEmpresa, ''), 'PLATAFORMA') as nomeEmpresa,
                COALESCE(NULLIF(c.viagem, ''), 'Ida') as sentido,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('embarque', 'ida') THEN c.totalPassageiros ELSE 0 END) as embarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) IN ('desembarque', 'volta') THEN c.totalPassageiros ELSE 0 END) as desembarques,
                SUM(CASE WHEN LOWER(c.tipoOperacao) = 'ocupação' OR LOWER(c.tipoOperacao) = 'ocupacao' THEN c.totalPassageiros ELSE 0 END) as ocupacaoInformada,
                AVG(CASE WHEN LOWER(c.tipoContagem) LIKE '%perc%' THEN c.totalPassageiros ELSE NULL END) as mediaPercentual,
                COUNT(DISTINCT c.idPesquisa) as totalSessoes
            FROM pesquisa c
            LEFT JOIN veiculo v ON c.idVeiculo = v.idVeiculo
            LEFT JOIN linha l ON c.idLinha = l.idLinha
            LEFT JOIN empresa e ON (c.idEmpresa = e.idEmpresa OR v.idEmpresa = e.idEmpresa OR l.idEmpresa = e.idEmpresa OR e.idEmpresa = 1)
            WHERE $whereSql
            GROUP BY COALESCE(e.idEmpresa, 1), COALESCE(NULLIF(e.nome, ''), NULLIF(e.nomeEmpresa, ''), 'PLATAFORMA'), COALESCE(NULLIF(c.viagem, ''), 'Ida')
            ORDER BY embarques DESC
        ");
        $stmtT4->execute($paramsHist);
        $dadosTabelaPorEmpresa = $stmtT4->fetchAll();
    } catch (PDOException $e) {}

} catch (PDOException $e) {}

$mediaOcupacaoVal = ($qtdPercentContagens > 0) ? round($somaOcupacaoPercent / $qtdPercentContagens, 1) : 0;
$mediaOcupacaoFmt = number_format($mediaOcupacaoVal, 1, ',', '.') . '%';
// Buscar opções para o formulário de início de contagem
$linhasDisponiveis = [];
try {
    $stmtL = $pdo->query("SELECT idLinha, numero, descricao, linha FROM linha WHERE ativo = 1 ORDER BY numero, descricao");
    if ($stmtL) $linhasDisponiveis = $stmtL->fetchAll();
} catch (\Exception $e) {}

$veiculosDisponiveis = [];
try {
    $stmtV = $pdo->query("SELECT * FROM veiculo WHERE ativo = 1 ORDER BY prefixo");
    if (!$stmtV) {
        $stmtV = $pdo->query("SELECT * FROM veiculos WHERE ativo = 1 ORDER BY prefixo");
    }
    if ($stmtV) $veiculosDisponiveis = $stmtV->fetchAll();
} catch (\Exception $e) {}

// Veículos selecionados especificamente para a sessão ativa de contagem
$veiculosSessao = [];
if ($sessaoAtiva) {
    $idsVeiculosSessao = [];
    if (!empty($sessaoAtiva['veiculosSelecionados'])) {
        $partesIds = explode(',', (string)$sessaoAtiva['veiculosSelecionados']);
        foreach ($partesIds as $pid) {
            $pidInt = (int)trim($pid);
            if ($pidInt > 0 && !in_array($pidInt, $idsVeiculosSessao)) {
                $idsVeiculosSessao[] = $pidInt;
            }
        }
    }
    // Inclui também o veículo ativo principal da sessão se não estiver na lista
    if (!empty($sessaoAtiva['idVeiculo']) && !in_array((int)$sessaoAtiva['idVeiculo'], $idsVeiculosSessao)) {
        $idsVeiculosSessao[] = (int)$sessaoAtiva['idVeiculo'];
    }

    if (!empty($idsVeiculosSessao)) {
        foreach ($veiculosDisponiveis as $v) {
            if (in_array((int)$v['idVeiculo'], $idsVeiculosSessao)) {
                $veiculosSessao[] = $v;
            }
        }
    }
}

$locaisDisponiveis = [];
try {
    $stmtLoc = $pdo->query("SELECT idLocal, local, regiao FROM locais WHERE COALESCE(ativo, 1) = 1 ORDER BY local");
    if ($stmtLoc) $locaisDisponiveis = $stmtLoc->fetchAll();
} catch (\Exception $e) {}

$empresasDisponiveis = [];
try {
    $stmtEmp = $pdo->query("SELECT idEmpresa, COALESCE(NULLIF(nome, ''), NULLIF(nomeEmpresa, ''), 'Empresa') as nome FROM empresa ORDER BY nome");
    if ($stmtEmp) $empresasDisponiveis = $stmtEmp->fetchAll();
} catch (\Exception $e) {}

// Carregar supervisores e gerentes para escolha no formulário de correção
$supervisoresDisponiveis = [];
try {
    $stmtSup = $pdo->query("SELECT idusuarios, nome, login, acesso, cargo FROM usuarios WHERE COALESCE(ativo, 1) = 1 AND (acesso IN (1, 2) OR LOWER(COALESCE(cargo, '')) IN ('administrador', 'admin', 'gerente', 'supervisor')) ORDER BY (acesso = 1) DESC, (acesso = 2) DESC, nome ASC");
    if ($stmtSup) $supervisoresDisponiveis = $stmtSup->fetchAll();
} catch (\Exception $e) {}

// Carregar solicitações de correção para a aba "Solicitações" (apenas visível para Administrador ou Gerente)
$solicitacoesCorrecao = [];
$totalPendentesNotif = 0;
$solicPendentesCount = 0;
$solicAprovadasCount = 0;
$solicRejeitadasCount = 0;

if ($canViewSolicitacoes) {
    try {
        $sqlSolic = "
            SELECT s.*, 
                   uSol.nome as solicitanteNome, uSol.login as solicitanteLogin,
                   uDest.nome as destinoNome, uDest.login as destinoLogin,
                   uResp.nome as respostaNome, uResp.login as respostaLogin,
                   c.idLinha, c.idVeiculo, c.idLocal, c.viagem as sentido, c.tipoContagem, c.totalPassageiros as totalContagem,
                   l.numero as linhaNumero, l.descricao as linhaDescricao,
                   v.prefixo as veiculoPrefixo,
                   loc.local as nomeLocal,
                   DATE_FORMAT(s.dataSolicitacao, '%d/%m/%Y %H:%i') as dataSolicitacaoFmt,
                   DATE_FORMAT(s.dataResposta, '%d/%m/%Y %H:%i') as dataRespostaFmt
            FROM solicitacoesCorrecao s
            LEFT JOIN usuarios uSol ON s.idUsuarioSolicitante = uSol.idusuarios
            LEFT JOIN usuarios uDest ON s.idUsuarioDestino = uDest.idusuarios
            LEFT JOIN usuarios uResp ON s.idUsuarioResposta = uResp.idusuarios
            LEFT JOIN pesquisa c ON s.idContagem = c.idPesquisa
            LEFT JOIN linha l ON c.idLinha = l.idLinha
            LEFT JOIN veiculo v ON c.idVeiculo = v.idVeiculo
            LEFT JOIN locais loc ON c.idLocal = loc.idLocal
            ORDER BY s.idSolicitacao DESC
        ";
        $stmtSolic = $pdo->query($sqlSolic);

        if ($stmtSolic) {
            $solicitacoesCorrecao = $stmtSolic->fetchAll();
            foreach ($solicitacoesCorrecao as $sc) {
                $st = strtolower($sc['status']);
                if ($st === 'pendente') {
                    $solicPendentesCount++;
                    if ($isAdmin || $isGerente || (int)$sc['idUsuarioDestino'] === $usuarioId) {
                        $totalPendentesNotif++;
                    }
                } elseif ($st === 'aprovada') {
                    $solicAprovadasCount++;
                } elseif ($st === 'rejeitada') {
                    $solicRejeitadasCount++;
                }
            }
        }
    } catch (\Exception $e) {}
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>contaBuzu — Contador & Histórico de Passageiros</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body style="padding-bottom: 60px;">

    <div class="dashboard-container" style="max-width: 1440px; margin: 20px auto; padding: 0 16px;">
        <div class="dashboard-card">

            <!-- Header da Aplicação -->
            <div class="dashboard-header" style="flex-wrap: wrap;">
                <div class="dashboard-user">
                    <div class="user-avatar">
                        <?php echo htmlspecialchars($initials); ?>
                    </div>
                    <div class="user-info">
                        <h2><?php echo htmlspecialchars($loggedNome); ?></h2>
                        <?php
                            $roleLabel = 'Pesquisador';
                            if ($isAdmin) {
                                $roleLabel = 'Administrador';
                            } elseif ($isGerente) {
                                $roleLabel = 'Gerente';
                            } elseif (!empty($loggedCargo)) {
                                $roleLabel = htmlspecialchars($loggedCargo);
                            }
                        ?>
                        <p>@<?php echo htmlspecialchars($loggedLogin); ?> • <?php echo $roleLabel; ?></p>
                    </div>
                </div>

                <div class="header-right-actions" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    
                    <!-- Relógio Digital em Tempo Real com Opção de Exibir Data -->
                    <div class="realtime-clock-card" title="Data e Horário em Tempo Real" style="display: inline-flex; align-items: center; gap: 8px; background: #ffffff; padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-weight: 600; font-size: 0.88rem; color: #1e293b; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <span class="pulse-dot" style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block;"></span>
                        <span id="realtime-clock"><?php echo date('H:i:s'); ?></span>
                        <button type="button" onclick="toggleDateInClock()" id="btn-toggle-date" title="Alternar exibição da data junto ao horário" style="background: #fee2e2; border: 1px solid #fecaca; border-radius: 6px; padding: 3px 8px; font-size: 0.75rem; cursor: pointer; color: #991b1b; font-weight: 600; font-family: inherit; transition: all 0.2s;">
                            📅 Data: OFF
                        </button>
                    </div>

                    <?php if ($isAdmin): ?>
                        <a href="admin_panel.php" class="btn-admin-link" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: #7c3aed; background-color: #faf5ff; padding: 9px 14px; border: 1px solid #f3e8ff; border-radius: 10px; font-size: 0.88rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.828c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.43l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.991l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            Painel Admin
                        </a>
                    <?php endif; ?>

                    <a href="logout.php" class="btn-logout" style="text-decoration: none; padding: 9px 14px; border-radius: 10px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
                        </svg>
                        Sair
                    </a>
                </div>
            </div>

            <!-- Navegação de Abas do Usuário -->
            <div class="tabs-navigation" style="margin: 20px 24px 0;">
                <button type="button" class="tab-btn active" id="btn-tab-contador" onclick="switchUserTab('tab-contador', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Painel de Contagem
                </button>
                <button type="button" class="tab-btn" id="btn-tab-historico" onclick="switchUserTab('tab-historico', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                    Dashboard <span class="tab-badge"><?php echo $totalSessoesUsuario; ?></span>
                </button>
                <?php if ($canViewSolicitacoes): ?>
                <button type="button" class="tab-btn" id="btn-tab-solicitacoes" onclick="switchUserTab('tab-solicitacoes', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                    Solicitações <?php if ($totalPendentesNotif > 0): ?><span class="tab-badge" style="background: #ef4444; color: #ffffff;"><?php echo $totalPendentesNotif; ?></span><?php else: ?><span class="tab-badge"><?php echo count($solicitacoesCorrecao); ?></span><?php endif; ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- Alertas e Notificações -->
            <div style="padding: 0 24px;">
                <?php if ($mensagemSucesso): ?>
                    <div class="alert alert-success" style="margin-top: 16px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <span><?php echo htmlspecialchars($mensagemSucesso); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($mensagemErro): ?>
                    <div class="alert alert-danger" style="margin-top: 16px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                        <span><?php echo htmlspecialchars($mensagemErro); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ÁREA PRINCIPAL DA APLICAÇÃO -->
            <div style="padding: 24px;">
                
                <!-- ABA 1: PAINEL DO CONTADOR (SESSÃO ATIVA OU NOVO INÍCIO) -->
                <div id="user-tab-contador" class="user-tab-content">
                    <?php if (!$sessaoAtiva): ?>
                        <?php
                            $h = (int)date('H');
                            if ($h >= 5 && $h < 12) {
                                $turnoAtual = 'Matutino';
                            } elseif ($h >= 12 && $h < 18) {
                                $turnoAtual = 'Vespertino';
                            } else {
                                $turnoAtual = 'Noturno';
                            }
                        ?>
                        <!-- FORMS: INICIAR NOVA SESSÃO DE CONTAGEM (DESIGN ATUALIZADO) -->
                        <form method="POST" action="index.php" id="form-iniciar-contagem" autocomplete="off">
                            <input type="hidden" name="action" value="iniciar_contagem">
                            <input type="hidden" name="condicaoTempo" id="input_condicaoTempo" value="">
                            <input type="hidden" name="tipoTransporte" id="input_tipoTransporte" value="">
                            <input type="hidden" name="tipoContagem" id="input_tipoContagem" value="">
                            <input type="hidden" name="tipoOperacao" id="input_tipoOperacao" value="">
                            <input type="hidden" name="sentido" id="input_sentido" value="">

                            <div class="panel-layout-grid">
                                <!-- ESQUERDA: DADOS -->
                                <div class="panel-card-column">
                                    <div class="panel-card">
                                        <div class="panel-card-header">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                            </svg>
                                            <span>Dados</span>
                                        </div>
                                        <div class="panel-card-body">
                                            <!-- Fiscal -->
                                            <div class="read-only-row">
                                                <div class="read-only-label">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                                        <circle cx="12" cy="7" r="4"></circle>
                                                    </svg>
                                                    <span>Fiscal</span>
                                                </div>
                                                <div class="read-only-value">
                                                    <strong class="highlight-bold"><?php echo htmlspecialchars($loggedNome); ?></strong>
                                                </div>
                                            </div>

                                            <!-- Data -->
                                            <div class="read-only-row">
                                                <div class="read-only-label">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                                    </svg>
                                                    <span>Data</span>
                                                </div>
                                                <div class="read-only-value">
                                                    <strong class="highlight-bold" id="panel-read-date"><?php echo date('d/m/Y'); ?></strong>
                                                </div>
                                            </div>

                                            <!-- Hora de inicio -->
                                            <div class="read-only-row">
                                                <div class="read-only-label">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <polyline points="12 6 12 12 16 14"></polyline>
                                                    </svg>
                                                    <span>Hora de Início</span>
                                                </div>
                                                <div class="read-only-value">
                                                    <strong class="highlight-bold" id="panel-read-time"><?php echo date('H:i'); ?></strong>
                                                </div>
                                            </div>

                                            <!-- Turno (Editável) -->
                                            <div class="read-only-row" style="align-items: center;">
                                                <div class="read-only-label">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="5"></circle>
                                                        <line x1="12" y1="1" x2="12" y2="3"></line>
                                                        <line x1="12" y1="21" x2="12" y2="23"></line>
                                                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                                                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                                                        <line x1="1" y1="12" x2="3" y2="12"></line>
                                                        <line x1="21" y1="12" x2="23" y2="12"></line>
                                                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                                                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                                                    </svg>
                                                    <span>Turno</span>
                                                </div>
                                                <div class="read-only-value" style="display: flex; align-items: center; gap: 6px;">
                                                    <select name="turno" id="select_iniciar_turno" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 4px 10px; font-weight: 700; font-size: 0.88rem; color: #000000; cursor: pointer; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,0.06);" title="Clique para alterar o turno desta contagem">
                                                        <option value="Matutino" <?php echo ($turnoAtual === 'Matutino') ? 'selected' : ''; ?>>Matutino</option>
                                                        <option value="Vespertino" <?php echo ($turnoAtual === 'Vespertino') ? 'selected' : ''; ?>>Vespertino</option>
                                                        <option value="Noturno" <?php echo ($turnoAtual === 'Noturno') ? 'selected' : ''; ?>>Noturno</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- DIREITA: SELEÇÕES OBRIGATÓRIAS -->
                                <div class="panel-card-column">
                                    <div class="panel-card panel-card-overflow-visible">
                                        <div class="panel-card-header">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                                            </svg>
                                            <span>Seleções Obrigatórias</span>
                                        </div>
                                        <div class="panel-card-body">
                                            
                                            <!-- 1. LINHA / LOCAL -->
                                            <div class="form-grid-2col">
                                                <div class="form-group-custom">
                                                    <label class="form-label-custom">
                                                        <span>Linha</span>
                                                        <span class="required-star">*</span>
                                                    </label>
                                                    <select name="idLinha" id="select_idLinha" required class="form-select-custom cb-searchable-select" data-placeholder="Digite o número ou nome da linha (ex: 0201, Barra)...">
                                                        <option value="">-- Selecione a Linha --</option>
                                                        <?php foreach ($linhasDisponiveis as $l): ?>
                                                            <option value="<?php echo $l['idLinha']; ?>">
                                                                <?php 
                                                                    $nomeL = !empty($l['numero']) ? $l['numero'] . ' - ' . $l['descricao'] : (!empty($l['linha']) ? $l['linha'] : 'Linha #' . $l['idLinha']);
                                                                    echo htmlspecialchars($nomeL);
                                                                ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="form-group-custom">
                                                    <label class="form-label-custom">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                                            <circle cx="12" cy="10" r="3"></circle>
                                                        </svg>
                                                        <span>Local</span>
                                                        <span class="required-star">*</span>
                                                    </label>
                                                    <select name="idLocal" id="select_idLocal" required class="form-select-custom cb-searchable-select" data-placeholder="Digite o nome do local ou região...">
                                                        <option value="">-- Selecione o Local --</option>
                                                        <?php foreach ($locaisDisponiveis as $loc): ?>
                                                            <option value="<?php echo $loc['idLocal']; ?>">
                                                                <?php echo htmlspecialchars($loc['local']); ?> <?php echo !empty($loc['regiao']) ? '('.htmlspecialchars($loc['regiao']).')' : ''; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- 2. VIAGEM / TIPO DE OPERAÇÃO -->
                                            <div class="form-grid-2col">
                                                <div class="form-group-custom">
                                                    <label class="form-label-custom">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <polygon points="3 11 22 2 13 21 11 13 3 11"></polygon>
                                                        </svg>
                                                        <span>Viagem</span>
                                                        <span class="required-star">*</span>
                                                    </label>
                                                    <div class="chips-flex-row">
                                                        <button type="button" id="chip-viagem-ida" class="chip-btn" onclick="selectChip('sentido', 'Ida', this, 'chip-blue')">
                                                            ➡️ Ida
                                                        </button>
                                                        <button type="button" id="chip-viagem-volta" class="chip-btn" onclick="selectChip('sentido', 'Volta', this, 'chip-blue')">
                                                            ⬅️ Volta
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="form-group-custom">
                                                    <label class="form-label-custom">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="17 1 21 5 17 9"></polyline>
                                                            <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                                                            <polyline points="7 23 3 19 7 15"></polyline>
                                                            <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                                                        </svg>
                                                        <span>Tipo de Operação</span>
                                                        <span class="required-star">*</span>
                                                    </label>
                                                    <div class="chips-flex-row">
                                                        <button type="button" id="chip-op-embarque" class="chip-btn" onclick="selectChip('tipoOperacao', 'embarque', this, 'chip-green')">
                                                            ↓ Embarque
                                                        </button>
                                                        <button type="button" id="chip-op-desembarque" class="chip-btn" onclick="selectChip('tipoOperacao', 'desembarque', this, 'chip-green')">
                                                            ↑ Desembarque
                                                        </button>
                                                        <button type="button" id="chip-op-ocupacao" class="chip-btn" onclick="selectChip('tipoOperacao', 'ocupacao', this, 'chip-green')">
                                                            👥 Ocupação
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 3. TIPO DE CONTAGEM / CONDIÇÃO DO TEMPO -->
                                            <div class="form-grid-2col">
                                                <div class="form-group-custom">
                                                    <label class="form-label-custom">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <line x1="19" y1="5" x2="5" y2="19"></line>
                                                            <circle cx="6.5" cy="6.5" r="2.5"></circle>
                                                            <circle cx="17.5" cy="17.5" r="2.5"></circle>
                                                        </svg>
                                                        <span>Tipo de Contagem</span>
                                                        <span class="required-star">*</span>
                                                    </label>
                                                    <div class="chips-flex-row">
                                                        <button type="button" id="chip-percentual" class="chip-btn" onclick="selectChip('tipoContagem', 'percentual', this, 'chip-purple')">
                                                            % Percentual
                                                        </button>
                                                        <button type="button" id="chip-quantidade" class="chip-btn" onclick="selectChip('tipoContagem', 'quantidade', this, 'chip-purple')">
                                                            👥 Quantidade
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="form-group-custom">
                                                    <label class="form-label-custom">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                                                        </svg>
                                                        <span>Condição do Tempo</span>
                                                        <span class="required-star">*</span>
                                                    </label>
                                                    <div class="chips-flex-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                                        <button type="button" id="chip-tempo-bom" class="chip-btn" style="justify-content: center; padding: 7px 8px; font-size: 0.82rem; white-space: nowrap;" onclick="selectChip('condicaoTempo', 'bom', this, 'chip-blue')">
                                                            ☀️ Bom
                                                        </button>
                                                        <button type="button" id="chip-tempo-nublado" class="chip-btn" style="justify-content: center; padding: 7px 8px; font-size: 0.82rem; white-space: nowrap;" onclick="selectChip('condicaoTempo', 'nublado', this, 'chip-blue')">
                                                            ☁️ Nublado
                                                        </button>
                                                        <button type="button" id="chip-tempo-chuva-leve" class="chip-btn" style="justify-content: center; padding: 7px 8px; font-size: 0.82rem; white-space: nowrap;" onclick="selectChip('condicaoTempo', 'chuva leve', this, 'chip-blue')">
                                                            🌧️ Chuva Leve
                                                        </button>
                                                        <button type="button" id="chip-tempo-chuva-densa" class="chip-btn" style="justify-content: center; padding: 7px 8px; font-size: 0.82rem; white-space: nowrap;" onclick="selectChip('condicaoTempo', 'chuva densa', this, 'chip-blue')">
                                                            ⛈️ Chuva Densa
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 4. TIPO DE TRANSPORTE -->
                                            <div class="form-group-custom">
                                                <label class="form-label-custom">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <rect x="3" y="6" width="18" height="13" rx="2"></rect>
                                                        <path d="M7 6V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v2"></path>
                                                        <circle cx="7" cy="15" r="1"></circle>
                                                        <circle cx="17" cy="15" r="1"></circle>
                                                    </svg>
                                                    <span>Tipo de Transporte</span>
                                                    <span class="required-star">*</span>
                                                </label>
                                                <div class="chips-flex-row">
                                                    <button type="button" id="chip-transp-stco" class="chip-btn" onclick="selectChip('tipoTransporte', 'stco', this, 'chip-blue')">
                                                        🚌 STCO
                                                    </button>
                                                    <button type="button" id="chip-transp-brt" class="chip-btn" onclick="selectChip('tipoTransporte', 'brt', this, 'chip-blue')">
                                                        🚌 BRT
                                                    </button>
                                                    <button type="button" id="chip-transp-stec" class="chip-btn" onclick="selectChip('tipoTransporte', 'stec', this, 'chip-blue')">
                                                        🚌 STEC
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- 5. VEÍCULO -->
                                            <div class="form-group-custom" id="veiculo-section-group">
                                                <label class="form-label-custom">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <rect x="3" y="6" width="18" height="13" rx="2"></rect>
                                                        <path d="M7 6V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v2"></path>
                                                        <circle cx="7" cy="15" r="1"></circle>
                                                        <circle cx="17" cy="15" r="1"></circle>
                                                    </svg>
                                                    <span>Veículo</span>
                                                    <span class="required-star">*</span>
                                                </label>

                                                <!-- Caixas de seleção: Registrar / Não informado -->
                                                <div style="display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;">
                                                    <label id="card-modo-registrar" onclick="selecionarModoVeiculo('registrar')" style="flex: 1; min-width: 140px; display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 2px solid #cbd5e1; background: #ffffff; border-radius: 10px; cursor: pointer; transition: all 0.2s; user-select: none;">
                                                        <input type="radio" name="opcaoVeiculo" id="radio_modo_registrar" value="registrar" style="accent-color: #2563eb; width: 18px; height: 18px; cursor: pointer;">
                                                        <div>
                                                            <div style="font-weight: 700; font-size: 0.9rem; color: #1e3a8a;">Registrar</div>
                                                            <div style="font-size: 0.75rem; color: #3b82f6;">Informar prefixos dos veículos</div>
                                                        </div>
                                                    </label>

                                                    <label id="card-modo-nao-informado" onclick="selecionarModoVeiculo('nao_informado')" style="flex: 1; min-width: 140px; display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 2px solid #cbd5e1; background: #ffffff; border-radius: 10px; cursor: pointer; transition: all 0.2s; user-select: none;">
                                                        <input type="radio" name="opcaoVeiculo" id="radio_modo_nao_informado" value="nao_informado" style="accent-color: #2563eb; width: 18px; height: 18px; cursor: pointer;">
                                                        <div>
                                                            <div style="font-weight: 700; font-size: 0.9rem; color: #475569;">Não informado</div>
                                                            <div style="font-size: 0.75rem; color: #94a3b8;">Continuar sem identificar veículo</div>
                                                        </div>
                                                    </label>
                                                </div>

                                                <!-- Bloco interativo quando 'Registrar' está ativo -->
                                                <div id="box-veiculo-registrar" style="display: none;">
                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                                                        <button type="button" onclick="openModalVeiculos()" style="display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: #ffffff; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 0.88rem; cursor: pointer; box-shadow: 0 2px 6px rgba(37,99,235,0.25); transition: all 0.2s;">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                                <circle cx="11" cy="11" r="8"></circle>
                                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                            </svg>
                                                            <span>Buscar e Escolher Veículo(s)</span>
                                                        </button>
                                                        <span id="badge-contador-veiculos" style="font-size: 0.82rem; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 6px;">0 selecionado(s)</span>
                                                    </div>

                                                    <!-- Container de badges dos veículos selecionados -->
                                                    <div id="container-veiculos-chips" style="display: flex; flex-wrap: wrap; gap: 8px; min-height: 44px; padding: 10px 12px; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; align-items: center;">
                                                        <span id="txt-sem-veiculo-hint" style="font-size: 0.82rem; color: #94a3b8; font-style: italic;">
                                                            Nenhum veículo selecionado ainda. Clique no botão acima para escolher os veículos para a contagem.
                                                        </span>
                                                    </div>

                                                    <!-- Inputs ocultos para submissão -->
                                                    <input type="hidden" name="idVeiculo" id="select_idVeiculo" value="">
                                                    <input type="hidden" name="veiculosSelecionados" id="input_veiculosSelecionados" value="">
                                                </div>

                                                <!-- Bloco informativo quando 'Não informado' está ativo -->
                                                <div id="box-veiculo-nao-informado" style="display: none; padding: 12px 14px; background: #f8fafc; border-radius: 10px; border: 1.5px solid #e2e8f0; color: #64748b; font-size: 0.86rem; font-weight: 600;">
                                                    <div style="display: flex; align-items: center; gap: 8px;">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #94a3b8; flex-shrink: 0;">
                                                            <circle cx="12" cy="12" r="10"></circle>
                                                            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                                                        </svg>
                                                        <span>Veículo não será informado nesta sessão de contagem.</span>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- BOTÃO PRINCIPAL DE INICIAR CONTAGEM -->
                            <div style="margin-top: 20px;">
                                <button type="submit" id="btn-iniciar-contagem" class="btn-iniciar-contagem-verde" disabled>
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86a1 1 0 0 0-1.5.86z"/>
                                    </svg>
                                    <span>Iniciar Contagem</span>
                                </button>
                                <div id="status-validacao-contagem" style="margin-top: 10px; padding: 10px 14px; border-radius: 10px; font-size: 0.84rem; text-align: center; background: #fff7ed; border: 1.5px solid #fed7aa; color: #c2410c; display: none; transition: all 0.2s;">
                                </div>
                            </div>
                        </form>

                    <?php else: ?>

                        <!-- DASHBOARD OPERACIONAL DO CONTADOR (SESSÃO ATIVA) -->
                        <div class="counter-wrapper">

                            <div class="session-card">
                                <div class="session-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                                    <div class="session-title">
                                        <span class="status-dot-small dot-green"></span>
                                        Sessão em Andamento
                                    </div>

                                    <div class="session-header-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                        <!-- Botão Solicitar Correção (Supervisor) -->
                                        <button type="button" onclick="openCorrectionModal()" id="btn-solicitar-correcao-topo" style="background: #fff1f2; color: #e11d48; border: 1.5px solid #fecdd3; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 1px 3px rgba(225,29,72,0.1); transition: all 0.2s;" onmouseover="this.style.background='#ffe4e6';" onmouseout="this.style.background='#fff1f2';">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="16" height="16">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                            <span>Solicitar Correção</span>
                                        </button>

                                        <!-- Botão Finalizar Contagem -->
                                        <form method="POST" action="index.php" onsubmit="return confirm('Deseja realmente ENCERRAR e salvar a contagem desta sessão?');">
                                            <input type="hidden" name="action" value="finalizar_contagem">
                                            <input type="hidden" name="idContagem" value="<?php echo $sessaoAtiva['idContagem']; ?>">
                                            <button type="submit" style="background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.background='#ffe4e6';" onmouseout="this.style.background='#fff1f2';">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                                                </svg>
                                                Finalizar Contagem
                                            </button>
                                        </form>

                                        <!-- Botão Cancelar Contagem -->
                                        <form method="POST" action="index.php" onsubmit="return confirm('Deseja realmente CANCELAR esta contagem? Esta ação não poderá ser desfeita.');">
                                            <input type="hidden" name="action" value="cancelar_contagem">
                                            <input type="hidden" name="idContagem" value="<?php echo $sessaoAtiva['idContagem']; ?>">
                                            <button type="submit" style="background: #f8fafc; color: #64748b; border: 1px solid #cbd5e1; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.background='#fef2f2'; this.style.color='#dc2626'; this.style.borderColor='#fca5a5';" onmouseout="this.style.background='#f8fafc'; this.style.color='#64748b'; this.style.borderColor='#cbd5e1';">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="16" height="16">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                                Cancelar Contagem
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <div class="session-info-grid">
                                    <span id="session-linha-text" style="display:none;"></span>
                                    <span id="session-veiculo-text" style="display:none;"></span>

                                    <div class="session-info-item session-info-clickable" onclick="openAlterarLinhaVeiculoModal()" title="Clique para editar Veículo ou Turno">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <label style="margin:0; cursor: pointer;">Turno</label>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #2563eb;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </div>
                                        <span id="session-turno-text" style="font-weight: 700; color: #000000;">
                                            <?php 
                                                $tAtiv = !empty($sessaoAtiva['turno']) ? $sessaoAtiva['turno'] : 'Matutino';
                                                echo htmlspecialchars($tAtiv); 
                                            ?>
                                        </span>
                                    </div>

                                    <div class="session-info-item">
                                        <label>Local de Parada</label>
                                        <span>
                                            <?php echo htmlspecialchars($sessaoAtiva['nomeLocal'] ? $sessaoAtiva['nomeLocal'] : 'Local'); ?>
                                        </span>
                                    </div>

                                    <div class="session-info-item">
                                        <label>Sentido (Viagem)</label>
                                        <span>
                                            <strong style="color: #0054A6; font-weight: 700;">
                                                <?php 
                                                    $sAtual = !empty($sessaoAtiva['sentido']) ? $sessaoAtiva['sentido'] : 'Ida';
                                                    echo (strtolower($sAtual) === 'volta') ? '⬅️ Volta' : '➡️ Ida';
                                                ?>
                                            </strong>
                                        </span>
                                    </div>

                                    <div class="session-info-item">
                                        <label>Modo de Contagem</label>
                                        <span>
                                            <strong style="color: <?php echo (isset($sessaoAtiva['tipoContagem']) && stripos($sessaoAtiva['tipoContagem'], 'perc') !== false) ? '#7e22ce' : '#0054A6'; ?>;">
                                                <?php echo (isset($sessaoAtiva['tipoContagem']) && stripos($sessaoAtiva['tipoContagem'], 'perc') !== false) ? '% Percentual' : '👥 Quantidade'; ?>
                                            </strong>
                                        </span>
                                    </div>
                                </div>

                                <?php
                                    $tipoCont = isset($sessaoAtiva['tipoContagem']) ? strtolower(trim($sessaoAtiva['tipoContagem'])) : 'percentual';
                                    
                                    if (!function_exists('calcPercentualStr')) {
                                        function calcPercentualStr($total) {
                                            $t = (int)$total;
                                            if ($t >= 100) return '120%';
                                            if ($t >= 82) return '100%';
                                            if ($t >= 54) return '65%';
                                            if ($t >= 42) return '50%';
                                            if ($t >= 30) return '35%';
                                            if ($t >= 13) return '15%';
                                            return '0%';
                                        }
                                    }
                                ?>

                                <!-- DISPLAY DO CONTADOR (PERCENTUAL OU QUANTIDADE) -->
                                <?php if ($tipoCont === 'percentual' || stripos($tipoCont, 'perc') !== false): ?>
                                    <div class="counter-display-card" style="background: linear-gradient(135deg, #4c1d95 0%, #7e22ce 100%); padding: 24px; text-align: center; border-radius: 16px; margin-bottom: 20px;">
                                        <div class="counter-label" style="color: #e9d5ff; font-weight: 600; font-size: 0.95rem;">Nível de Ocupação (% Estimada)</div>
                                        <div class="counter-number" id="counterTotalPercent" style="color: #ffffff; font-size: 3.5rem; font-weight: 800; text-shadow: 0 4px 12px rgba(0,0,0,0.3); margin: 6px 0;"><?php echo calcPercentualStr($sessaoAtiva['totalPassageiros']); ?></div>
                                        <div style="color: #d8b4fe; font-size: 0.95rem; font-weight: 600;">
                                            Total acumulado: <span id="counterTotalNum" style="color: #ffffff; font-weight: 800;"><?php echo (int)$sessaoAtiva['totalPassageiros']; ?></span> passageiros
                                        </div>
                                    </div>

                                    <!-- BOTOEIRA DE DEFINIÇÃO EM PORCENTAGEM (SUBSTITUIÇÃO DE VALOR) -->
                                    <div class="percent-btn-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px;">
                                        <?php 
                                            $totAtual = (int)$sessaoAtiva['totalPassageiros'];
                                            $opcoesPct = [
                                                ['pct' => '0%',   'qtd' => 0,   'label' => '(0 pas.)',    'bg' => '#f8fafc', 'border' => '#64748b', 'color' => '#334155'],
                                                ['pct' => '15%',  'qtd' => 13,  'label' => '(13 pas.)',   'bg' => '#eff6ff', 'border' => '#3b82f6', 'color' => '#1d4ed8'],
                                                ['pct' => '35%',  'qtd' => 30,  'label' => '(30 pas.)',   'bg' => '#eff6ff', 'border' => '#3b82f6', 'color' => '#1d4ed8'],
                                                ['pct' => '50%',  'qtd' => 42,  'label' => '(42 pas.)',   'bg' => '#f0fdf4', 'border' => '#22c55e', 'color' => '#15803d'],
                                                ['pct' => '65%',  'qtd' => 54,  'label' => '(54 pas.)',   'bg' => '#fefce8', 'border' => '#eab308', 'color' => '#854d0e'],
                                                ['pct' => '100%', 'qtd' => 82,  'label' => '(82 pas.)',   'bg' => '#faf5ff', 'border' => '#a855f7', 'color' => '#6b21a8'],
                                                ['pct' => '120%', 'qtd' => 100, 'label' => '(>100 pas.)', 'bg' => '#fff1f2', 'border' => '#f43f5e', 'color' => '#be123c'],
                                            ];
                                        ?>
                                        <?php foreach ($opcoesPct as $op): ?>
                                            <?php 
                                                $isAtivoPct = ($totAtual === $op['qtd']);
                                            ?>
                                            <button type="button" 
                                                    class="btn-count-percent <?php echo $isAtivoPct ? 'active-percent' : ''; ?>" 
                                                    data-qtd="<?php echo $op['qtd']; ?>"
                                                    onclick="sendIncrement(<?php echo $op['qtd']; ?>, 'substituir', this)" 
                                                    style="background: <?php echo $op['bg']; ?>; border: 2.5px solid <?php echo $op['border']; ?>; color: <?php echo $op['color']; ?>; border-radius: 14px; padding: 14px 10px; font-family: inherit; cursor: pointer; text-align: center; transition: all 0.2s; <?php echo $isAtivoPct ? 'box-shadow: 0 6px 16px rgba(0,0,0,0.15); transform: translateY(-2px) scale(1.03);' : ''; ?>">
                                                <div style="font-size: 1.4rem; font-weight: 800;"><?php echo $op['pct']; ?></div>
                                                <div style="font-size: 0.8rem; font-weight: 600; opacity: 0.85;"><?php echo $op['label']; ?></div>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>

                                    <div style="margin-bottom: 20px;">
                                        <button type="button" class="btn-count btn-save-action" onclick="salvarContagemAtiva()" id="btn-salvar-contagem" style="width: 100%; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: none; padding: 15px; border-radius: 14px; font-weight: 800; font-size: 1.05rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 14px rgba(37,99,235,0.3); transition: all 0.2s;">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="22" height="22">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c.11.128.19.278.234.442l.7 2.622A2.25 2.25 0 0 1 16.34 9H7.66a2.25 2.25 0 0 1-2.187-2.616l.7-2.622a2.25 2.25 0 0 1 .476-.884l1.5-1.5A2.25 2.25 0 0 1 9.75 1h4.5c.597 0 1.17.238 1.591.659l1.752 1.663zM12 12a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                                            </svg>
                                            <span>Salvar Contagem</span>
                                        </button>
                                    </div>

                                <?php else: ?>

                                    <!-- BOTOEIRA NUMÉRICA PADRÃO -->
                                    <div class="counter-display-card">
                                        <div class="counter-label">Total de Passageiros Embarcados</div>
                                        <div class="counter-number" id="counterTotal"><?php echo (int)$sessaoAtiva['totalPassageiros']; ?></div>
                                    </div>

                                    <div class="counter-btn-grid">
                                        <button type="button" class="btn-count btn-plus-1" onclick="sendIncrement(1)">
                                            <span>+1</span>
                                            <span class="btn-count-subtext">Passageiro</span>
                                        </button>

                                        <button type="button" class="btn-count btn-plus-5" onclick="sendIncrement(5)">
                                            <span>+5</span>
                                            <span class="btn-count-subtext">Grupo</span>
                                        </button>

                                        <button type="button" class="btn-count btn-plus-10" onclick="sendIncrement(10)">
                                            <span>+10</span>
                                            <span class="btn-count-subtext">Lote Rápido</span>
                                        </button>

                                        <button type="button" class="btn-count btn-save-action" onclick="salvarContagemAtiva()" id="btn-salvar-contagem-grid">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="22" height="22">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c.11.128.19.278.234.442l.7 2.622A2.25 2.25 0 0 1 16.34 9H7.66a2.25 2.25 0 0 1-2.187-2.616l.7-2.622a2.25 2.25 0 0 1 .476-.884l1.5-1.5A2.25 2.25 0 0 1 9.75 1h4.5c.597 0 1.17.238 1.591.659l1.752 1.663zM12 12a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                                            </svg>
                                            <span>Salvar Contagem</span>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <!-- SELETOR DE ÔNIBUS E LINHA PARA DIRECIONAR A CONTAGEM -->
                                <div class="onibus-selection-container" style="margin-top: 24px; padding: 20px; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                                        <div>
                                            <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="6" width="18" height="13" rx="2"></rect>
                                                    <path d="M7 6V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v2"></path>
                                                    <circle cx="7" cy="15" r="1"></circle>
                                                    <circle cx="17" cy="15" r="1"></circle>
                                                </svg>
                                                Ônibus & Linha para Adicionar Pessoas
                                            </h4>
                                            <div style="font-size: 0.82rem; color: #64748b; margin-top: 3px;">
                                                Selecione abaixo em qual ônibus ou linha os passageiros contados serão adicionados.
                                            </div>
                                        </div>

                                        <!-- Indicador da Seleção Ativa Atual -->
                                        <div id="destaque-selecao-ativa" style="display: flex; align-items: center; gap: 8px; background: #f0f7ff; border: 1.5px solid #bfdbfe; padding: 8px 14px; border-radius: 10px; flex-wrap: wrap;">
                                            <span style="font-size: 0.8rem; color: #1e40af; font-weight: 700;">Ativo para contagem:</span>
                                            <span id="badge-linha-ativa-mini" style="font-size: 0.82rem; font-weight: 800; color: #1d4ed8; background: #dbeafe; padding: 4px 10px; border-radius: 6px;">
                                                Linha: <?php echo htmlspecialchars(!empty($sessaoAtiva['linhaNumero']) ? $sessaoAtiva['linhaNumero'] . ' - ' . $sessaoAtiva['linhaDescricao'] : 'Linha #'.$sessaoAtiva['idLinha']); ?>
                                            </span>
                                            <span id="badge-veiculo-ativo-mini" style="font-size: 0.82rem; font-weight: 800; color: #15803d; background: #dcfce7; padding: 4px 10px; border-radius: 6px;">
                                                Ônibus: <?php echo htmlspecialchars(!empty($sessaoAtiva['veiculoPrefixo']) ? $sessaoAtiva['veiculoPrefixo'] : 'Prefixo #'.$sessaoAtiva['idVeiculo']); ?>
                                            </span>
                                        </div>
                                    </div>


                                    <!-- 2. OPÇÕES DE ÔNIBUS SELECIONADOS PARA ESTA SESSÃO DE CONTAGEM -->
                                    <div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                                            <label style="font-weight: 700; font-size: 0.88rem; color: #334155; display: flex; align-items: center; gap: 6px;">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #2563eb;">
                                                    <rect x="3" y="6" width="18" height="13" rx="2"></rect>
                                                    <path d="M7 6V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v2"></path>
                                                    <circle cx="7" cy="15" r="1"></circle>
                                                    <circle cx="17" cy="15" r="1"></circle>
                                                </svg>
                                                Ônibus Selecionados para esta Contagem:
                                                <span id="badge-total-onibus-cadastrados" style="font-size: 0.78rem; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 2px 8px; border-radius: 9999px; border: 1px solid #bfdbfe;">
                                                    <?php echo count($veiculosSessao); ?> selecionado(s)
                                                </span>
                                            </label>

                                            <!-- Ações Rápidas: Botão de Registrar / Escolher Mais Ônibus -->
                                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                                <!-- Botão Registrar / Selecionar Mais Ônibus para a Contagem -->
                                                <button type="button" onclick="openModalGerenciarVeiculosSessao()" class="btn-gerenciar-veiculos-sessao" title="Registrar ou selecionar mais ônibus para esta contagem" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; box-shadow: 0 2px 6px rgba(37,99,235,0.25); transition: all 0.2s; white-space: nowrap;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                                                        <line x1="12" y1="5" x2="12" y2="19"></line>
                                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                                    </svg>
                                                    <span>+ Registrar / Escolher Ônibus</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Grade de cards apenas com os ônibus selecionados para esta contagem -->
                                        <div id="grid-onibus-registrados" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; max-height: 240px; overflow-y: auto; padding: 6px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px;">
                                            <?php if (count($veiculosSessao) > 0): ?>
                                                <?php foreach ($veiculosSessao as $v): ?>
                                                    <?php 
                                                        $isVeicAtivo = ($sessaoAtiva && $sessaoAtiva['idVeiculo'] == $v['idVeiculo']);
                                                        $pref = htmlspecialchars($v['prefixo']);
                                                        $plc = !empty($v['placa']) ? htmlspecialchars($v['placa']) : '';
                                                        $searchData = strtolower($v['prefixo'] . ' ' . $plc);
                                                    ?>
                                                    <button type="button" 
                                                            class="btn-opcao-onibus <?php echo $isVeicAtivo ? 'onibus-ativo' : ''; ?>" 
                                                            id="btn-veiculo-opt-<?php echo $v['idVeiculo']; ?>"
                                                            data-id-veiculo="<?php echo $v['idVeiculo']; ?>" 
                                                            data-prefixo="<?php echo $pref; ?>"
                                                            data-placa="<?php echo $plc; ?>"
                                                            data-search="<?php echo $searchData; ?>"
                                                            onclick="selecionarOnibusContagem(<?php echo $v['idVeiculo']; ?>, '<?php echo addslashes($pref); ?>')"
                                                            style="display: flex; flex-direction: column; align-items: flex-start; padding: 10px 12px; border-radius: 10px; border: 2px solid <?php echo $isVeicAtivo ? '#2563eb' : '#cbd5e1'; ?>; background: <?php echo $isVeicAtivo ? '#eff6ff' : '#ffffff'; ?>; cursor: pointer; text-align: left; transition: all 0.2s; position: relative;">
                                                        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; margin-bottom: 3px;">
                                                            <span style="font-weight: 800; font-size: 0.95rem; color: <?php echo $isVeicAtivo ? '#1d4ed8' : '#0f172a'; ?>;">
                                                                🚌 <?php echo $pref; ?>
                                                            </span>
                                                            <span class="badge-onibus-status" style="font-size: 0.68rem; font-weight: 800; color: #16a34a; background: #dcfce7; padding: 2px 6px; border-radius: 4px; display: <?php echo $isVeicAtivo ? 'inline-block' : 'none'; ?>;">
                                                                ✓ ATIVO
                                                            </span>
                                                        </div>
                                                        <?php if ($plc): ?>
                                                            <div style="font-size: 0.75rem; color: #64748b; font-weight: 600;">
                                                                Placa: <?php echo $plc; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div style="grid-column: 1 / -1; padding: 18px; text-align: center; color: #64748b; font-size: 0.88rem; background: #ffffff; border-radius: 10px; border: 1px dashed #cbd5e1;">
                                                    Nenhum ônibus selecionado para esta sessão de contagem.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div id="aviso-onibus-nao-encontrado" style="display: none; padding: 16px; text-align: center; color: #94a3b8; font-size: 0.88rem; font-style: italic; background: #ffffff; border-radius: 10px; margin-top: 6px; border: 1px dashed #cbd5e1;">
                                            Nenhum ônibus encontrado correspondente ao filtro.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HISTÓRICO DE LANÇAMENTOS E AUDITORIA DA SESSÃO ATIVA -->
                            <div class="session-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                                    <h3 style="font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20" style="color: #0054A6;">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                        Registros Recentes desta Sessão
                                    </h3>

                                    <button type="button" onclick="openExpandLogsModal()" class="btn-expand-logs" style="background: #f1f5f9; color: #0054A6; border: 1px solid #cbd5e1; padding: 7px 14px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="16" height="16">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                                        </svg>
                                        <span>Expandir Tabela (Tela Cheia)</span>
                                    </button>
                                </div>

                                <div style="overflow-x: auto;">
                                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                                        <thead>
                                            <tr style="border-bottom: 2px solid #e2e8f0; color: #64748b; font-size: 0.78rem; text-transform: uppercase;">
                                                <th style="padding: 10px 12px;">Horário</th>
                                                <th style="padding: 10px 12px;">Operação</th>
                                                <th style="padding: 10px 12px;">Quantidade</th>
                                                <th style="padding: 10px 12px;">Observação / Justificativa</th>
                                            </tr>
                                        </thead>
                                        <tbody id="logsTableBody">
                                            <?php if (count($registrosSessao) === 0): ?>
                                                <tr>
                                                    <td colspan="4" style="padding: 24px; text-align: center; color: #94a3b8;">
                                                        Nenhum registro efetuado ainda nesta sessão. Use os botões acima para iniciar a contagem.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($registrosSessao as $reg): ?>
                                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                                        <td style="padding: 12px; font-weight: 600; color: #475569;">
                                                            <?php echo htmlspecialchars($reg['horaFormatada']); ?>
                                                        </td>
                                                        <td style="padding: 12px;">
                                                            <?php if ($reg['tipo'] === 'incremento'): ?>
                                                                <span class="badge-reg badge-inc">+ Entrada</span>
                                                            <?php elseif ($reg['tipo'] === 'salvamento'): ?>
                                                                <span class="badge-reg badge-salvo">💾 Salvo</span>
                                                            <?php else: ?>
                                                                <span class="badge-reg badge-corr">- Correção</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="padding: 12px; font-weight: 800; font-size: 1.05rem; color: <?php echo $reg['tipo'] === 'salvamento' ? '#2563eb' : ($reg['quantidade'] > 0 ? '#16a34a' : '#dc2626'); ?>;">
                                                            <?php 
                                                                if ($reg['tipo'] === 'salvamento') {
                                                                    echo (isset($tipoCont) && stripos($tipoCont, 'perc') !== false) 
                                                                        ? calcPercentualStr($reg['quantidade']) . ' (' . (int)$reg['quantidade'] . ' pas.)' 
                                                                        : (int)$reg['quantidade'] . ' pas.';
                                                                } else {
                                                                    echo (($reg['quantidade'] > 0 ? '+' : '') . (int)$reg['quantidade']);
                                                                }
                                                            ?>
                                                        </td>
                                                        <td style="padding: 12px; color: #334155;">
                                                            <?php 
                                                                if ($reg['tipo'] === 'salvamento' || (!empty($reg['justificativa']) && strpos($reg['justificativa'], 'Operação Salva.') === 0)) {
                                                                    echo '<span style="color: #15803d; font-weight: 600;">✓ Contagem salva</span>';
                                                                } elseif (!empty($reg['justificativa'])) {
                                                                    echo htmlspecialchars($reg['justificativa']);
                                                                } else {
                                                                    echo '<em style="color:#a1a1aa;">Contagem direta</em>';
                                                                }
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>

                <!-- ABA 2: MEU HISTÓRICO COMPLETO DE CONTAGENS (NOVO DESIGN) -->
                <div id="user-tab-historico" class="user-tab-content" style="display: none;">
                    
                    <!-- 1. CARTÃO DE FILTROS DA PESQUISA (LAYOUT FIDELIDADE IMAGEM) -->
                    <div class="panel-card" style="margin-bottom: 20px; border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                        <div class="panel-card-body" style="padding: 16px 20px;">
                            <form method="GET" action="index.php" id="form-filtros-historico">
                                <input type="hidden" name="tab" value="historico">
                                
                                <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px;">
                                    
                                    <!-- GROUP 1: PERÍODO -->
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <span style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a;">Período</span>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            
                                            <!-- Data início -->
                                            <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 10px; background: #ffffff; min-width: 140px; display: flex; flex-direction: column; cursor: pointer; transition: all 0.2s ease;">
                                                <label style="font-size: 0.7rem; color: #475569; font-weight: 700; line-height: 1; pointer-events: none; cursor: pointer; user-select: none;">Data de início</label>
                                                <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($filtroDataInicio); ?>" onchange="this.form.submit()" onclick="if(typeof this.showPicker==='function'){try{this.showPicker();}catch(e){}}" style="border: none; outline: none; background: transparent; font-size: 0.84rem; font-weight: 700; color: #1e293b; width: 100%; margin-top: 2px; cursor: pointer;">
                                            </div>

                                            <!-- Data fim -->
                                            <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 10px; background: #ffffff; min-width: 140px; display: flex; flex-direction: column; cursor: pointer; transition: all 0.2s ease;">
                                                <label style="font-size: 0.7rem; color: #475569; font-weight: 700; line-height: 1; pointer-events: none; cursor: pointer; user-select: none;">Data de fim</label>
                                                <input type="date" name="data_fim" value="<?php echo htmlspecialchars($filtroDataFim); ?>" onchange="this.form.submit()" onclick="if(typeof this.showPicker==='function'){try{this.showPicker();}catch(e){}}" style="border: none; outline: none; background: transparent; font-size: 0.84rem; font-weight: 700; color: #1e293b; width: 100%; margin-top: 2px; cursor: pointer;">
                                            </div>

                                        </div>
                                    </div>

                                    <!-- GROUP 2: HORÁRIO -->
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <span style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a;">Horário</span>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            
                                            <!-- Hora início -->
                                            <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 10px; background: #ffffff; width: 110px; display: flex; flex-direction: column; cursor: pointer; transition: all 0.2s ease;">
                                                <label style="font-size: 0.7rem; color: #475569; font-weight: 700; line-height: 1; pointer-events: none; cursor: pointer; user-select: none;">Hora início</label>
                                                <input type="time" name="hora_inicio" value="<?php echo htmlspecialchars($filtroHoraInicio); ?>" step="60" onchange="this.form.submit()" onclick="if(typeof this.showPicker==='function'){try{this.showPicker();}catch(e){}}" style="border: none; outline: none; background: transparent; font-size: 0.84rem; font-weight: 700; color: #1e293b; width: 100%; margin-top: 2px; cursor: pointer;">
                                            </div>

                                            <!-- Hora fim -->
                                            <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 10px; background: #ffffff; width: 110px; display: flex; flex-direction: column; cursor: pointer; transition: all 0.2s ease;">
                                                <label style="font-size: 0.7rem; color: #475569; font-weight: 700; line-height: 1; pointer-events: none; cursor: pointer; user-select: none;">Hora fim</label>
                                                <input type="time" name="hora_fim" value="<?php echo htmlspecialchars($filtroHoraFim); ?>" step="60" onchange="this.form.submit()" onclick="if(typeof this.showPicker==='function'){try{this.showPicker();}catch(e){}}" style="border: none; outline: none; background: transparent; font-size: 0.84rem; font-weight: 700; color: #1e293b; width: 100%; margin-top: 2px; cursor: pointer;">
                                            </div>

                                        </div>
                                    </div>

                                    <!-- GROUP 3: EMPRESA -->
                                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 140px; flex: 1;">
                                        <span onclick="triggerInputPicker(this.nextElementSibling, event)" style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a; cursor: pointer; user-select: none;">Empresa</span>
                                        <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; background: #ffffff; height: 42px; display: flex; align-items: center; cursor: pointer; position: relative; transition: all 0.2s ease;">
                                            <select name="idEmpresa" onchange="this.form.submit()" style="border: none; outline: none; background: transparent; font-size: 0.86rem; font-weight: 600; color: #1e293b; width: 100%; height: 100%; cursor: pointer;">
                                                <option value="">Todas</option>
                                                <?php foreach ($empresasDisponiveis as $emp): ?>
                                                    <option value="<?php echo $emp['idEmpresa']; ?>" <?php echo ($filtroEmpresa == $emp['idEmpresa']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($emp['nome']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- GROUP 4: LINHA -->
                                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 140px; flex: 1;">
                                        <span onclick="triggerInputPicker(this.nextElementSibling, event)" style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a; cursor: pointer; user-select: none;">Linha</span>
                                        <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; background: #ffffff; height: 42px; display: flex; align-items: center; cursor: pointer; position: relative; transition: all 0.2s ease;">
                                            <select name="idLinha" onchange="this.form.submit()" style="border: none; outline: none; background: transparent; font-size: 0.86rem; font-weight: 600; color: #1e293b; width: 100%; height: 100%; cursor: pointer;">
                                                <option value="">Todas</option>
                                                <?php foreach ($linhasDisponiveis as $l): ?>
                                                    <option value="<?php echo $l['idLinha']; ?>" <?php echo ($filtroLinha == $l['idLinha']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars(!empty($l['numero']) ? $l['numero'] . ' - ' . $l['descricao'] : 'Linha #' . $l['idLinha']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- GROUP 5: VEÍCULO -->
                                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 130px; flex: 1;">
                                        <span onclick="triggerInputPicker(this.nextElementSibling, event)" style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a; cursor: pointer; user-select: none;">Veículo</span>
                                        <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; background: #ffffff; height: 42px; display: flex; align-items: center; cursor: pointer; position: relative; transition: all 0.2s ease;">
                                            <select name="idVeiculo" onchange="this.form.submit()" style="border: none; outline: none; background: transparent; font-size: 0.86rem; font-weight: 600; color: #1e293b; width: 100%; height: 100%; cursor: pointer;">
                                                <option value="">Todos</option>
                                                <?php foreach ($veiculosDisponiveis as $v): ?>
                                                    <option value="<?php echo $v['idVeiculo']; ?>" <?php echo ($filtroVeiculo == $v['idVeiculo']) ? 'selected' : ''; ?>>
                                                        Prefixo <?php echo htmlspecialchars($v['prefixo']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- GROUP 6: LOCAL -->
                                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 140px; flex: 1;">
                                        <span onclick="triggerInputPicker(this.nextElementSibling, event)" style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a; cursor: pointer; user-select: none;">Local</span>
                                        <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; background: #ffffff; height: 42px; display: flex; align-items: center; cursor: pointer; position: relative; transition: all 0.2s ease;">
                                            <select name="idLocal" onchange="this.form.submit()" style="border: none; outline: none; background: transparent; font-size: 0.86rem; font-weight: 600; color: #1e293b; width: 100%; height: 100%; cursor: pointer;">
                                                <option value="">Todos</option>
                                                <?php foreach ($locaisDisponiveis as $loc): ?>
                                                    <option value="<?php echo $loc['idLocal']; ?>" <?php echo ($filtroLocal == $loc['idLocal']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($loc['local']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- GROUP 7: TIPO VIAGEM -->
                                    <div style="display: flex; flex-direction: column; gap: 4px; min-width: 130px; flex: 1;">
                                        <span onclick="triggerInputPicker(this.nextElementSibling, event)" style="font-weight: 800; font-size: 0.82rem; color: #1e3a8a; cursor: pointer; user-select: none;">Tipo Viagem</span>
                                        <div onclick="triggerInputPicker(this, event)" class="filter-box-clickable" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; background: #ffffff; height: 42px; display: flex; align-items: center; cursor: pointer; position: relative; transition: all 0.2s ease;">
                                            <select name="tipoOperacao" onchange="this.form.submit()" style="border: none; outline: none; background: transparent; font-size: 0.86rem; font-weight: 600; color: #1e293b; width: 100%; height: 100%; cursor: pointer;">
                                                <option value="">Todos</option>
                                                <option value="embarque" <?php echo ($filtroTipoOp === 'embarque') ? 'selected' : ''; ?>>Embarque</option>
                                                <option value="desembarque" <?php echo ($filtroTipoOp === 'desembarque') ? 'selected' : ''; ?>>Desembarque</option>
                                                <option value="ida" <?php echo ($filtroTipoOp === 'ida') ? 'selected' : ''; ?>>Ida</option>
                                                <option value="volta" <?php echo ($filtroTipoOp === 'volta') ? 'selected' : ''; ?>>Volta</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- ACTIONS BUTTONS -->
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <button type="submit" style="background: #2563eb; color: #ffffff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; font-size: 0.86rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(37,99,235,0.25); height: 42px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="11" cy="11" r="8"></circle>
                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                            </svg>
                                            <span>Filtrar</span>
                                        </button>

                                        <a href="index.php?tab=historico" style="background: #ffffff; color: #334155; border: 1px solid #cbd5e1; padding: 9px 14px; border-radius: 8px; font-weight: 600; font-size: 0.86rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; height: 42px; box-sizing: border-box;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
                                            </svg>
                                            <span>Limpar</span>
                                        </a>
                                    </div>

                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- 2. QUADRO DE KPI METRIC CARDS (GRID DE 5 CARTÕES DE DESTAQUE) -->
                    <div class="kpi-cards-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px;">
                        
                        <!-- Card 1: Total de Viagens -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                            <div style="color: #475569; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>🚍 Total de Viagens</span>
                            </div>
                            <div style="color: #0f172a; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo number_format($totalSessoesUsuario, 0, ',', '.'); ?>
                            </div>
                            <div style="color: #64748b; font-size: 0.78rem; font-weight: 600;">
                                Total no período
                            </div>
                        </div>

                        <!-- Card 2: Embarques -->
                        <div style="background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #15803d; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>↑ Total Embarques</span>
                            </div>
                            <div style="color: #15803d; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo number_format($totalEmbarquesPeriodo, 0, ',', '.'); ?>
                            </div>
                            <div style="color: #166534; font-size: 0.78rem; font-weight: 600; opacity: 0.85;">
                                Total no período
                            </div>
                        </div>

                        <!-- Card 3: Desembarques -->
                        <div style="background: #ffe4e6; border: 1px solid #fecdd3; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #be123c; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>↓ Total Desembarques</span>
                            </div>
                            <div style="color: #be123c; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo number_format($totalDesembarquesPeriodo, 0, ',', '.'); ?>
                            </div>
                            <div style="color: #9f1239; font-size: 0.78rem; font-weight: 600; opacity: 0.85;">
                                Total no período
                            </div>
                        </div>

                        <!-- Card 4: Ocupação % -->
                        <div style="background: #dbeafe; border: 1px solid #bfdbfe; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #1d4ed8; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>🚌 Total Ocupação</span>
                            </div>
                            <div style="color: #1d4ed8; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo $mediaOcupacaoFmt; ?>
                            </div>
                            <div style="color: #1e40af; font-size: 0.78rem; font-weight: 600; opacity: 0.85;">
                                Média no período
                            </div>
                        </div>

                        <!-- Card 5: Passageiros Totais -->
                        <div style="background: #faf5ff; border: 1px solid #f3e8ff; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #7c3aed; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>👥 Passageiros Totais</span>
                            </div>
                            <div style="color: #6d28d9; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo number_format($passageirosTotaisPeriodo, 0, ',', '.'); ?>
                            </div>
                            <div style="color: #5b21b6; font-size: 0.78rem; font-weight: 600; opacity: 0.85;">
                                Total no período
                            </div>
                        </div>

                    </div>
                    <div class="dash-tables-nav" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; background: #ffffff; padding: 10px 14px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
                        <button type="button" class="dash-nav-btn active" id="btn-dash-t1" onclick="switchDashTable('dash-t1', this)" style="background: #2563eb; color: #ffffff; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; border: none; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 2px 6px rgba(37,99,235,0.25);">
                            📊 1. Passageiros por Data / Hora / Linha / Veículo
                        </button>
                        <button type="button" class="dash-nav-btn" id="btn-dash-t2" onclick="switchDashTable('dash-t2', this)" style="background: #f1f5f9; color: #475569; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; border: 1px solid #cbd5e1; cursor: pointer; transition: all 0.2s ease;">
                            🚍 2. Total por Linha
                        </button>
                        <button type="button" class="dash-nav-btn" id="btn-dash-t3" onclick="switchDashTable('dash-t3', this)" style="background: #f1f5f9; color: #475569; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; border: 1px solid #cbd5e1; cursor: pointer; transition: all 0.2s ease;">
                            📍 3. Por Local
                        </button>
                        <button type="button" class="dash-nav-btn" id="btn-dash-t4" onclick="switchDashTable('dash-t4', this)" style="background: #f1f5f9; color: #475569; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; border: 1px solid #cbd5e1; cursor: pointer; transition: all 0.2s ease;">
                            🏢 4. Por Empresa / Cooperativa
                        </button>
                    </div>

                    <!-- TABELA 1: QUANTIDADE PASSAGEIROS POR DATA/ HORA/ LINHA/ VEÍCULO -->
                    <div id="container-dash-t1" class="dash-table-card panel-card" style="margin-bottom: 24px;">
                        <div class="panel-card-header" style="background: #ffffff; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                    <span>📊 Quantidade passageiros por data/ hora/ linha/ veículo</span>
                                </h3>
                                <p style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">Detalhamento individual de contagens por data, horário, linha e veículo</p>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <input type="text" oninput="filterTableRows('tbl-t1', this.value)" placeholder="Buscar data, linha, veículo, viagem..." style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; width: 220px; outline: none; background: #ffffff;">
                                <div class="export-dropdown-wrapper" style="position: relative; display: inline-block;">
                                    <button type="button" onclick="toggleExportMenu('export-menu-t1', event)" style="background: #0f172a; color: #ffffff; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(15,23,42,0.2);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        <span>Exportar</span>
                                    </button>
                                    <div id="export-menu-t1" class="export-menu-box" style="display: none; position: absolute; right: 0; top: 110%; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); min-width: 170px; z-index: 100; padding: 6px 0;">
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t1', 'pdf', 'Quantidade_Passageiros_Data_Hora_Linha_Veiculo')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #be123c; font-weight: 700; text-decoration: none;">
                                            📄 Exportar para PDF
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t1', 'csv', 'Quantidade_Passageiros_Data_Hora_Linha_Veiculo')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #15803d; font-weight: 700; text-decoration: none;">
                                            📊 Exportar para CSV
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t1', 'doc', 'Quantidade_Passageiros_Data_Hora_Linha_Veiculo')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #1d4ed8; font-weight: 700; text-decoration: none;">
                                            📝 Exportar para DOC
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="overflow-x: auto; padding: 12px 20px 20px;">
                            <table class="apps-table" id="tbl-t1" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <th style="padding: 12px 10px; text-align: center;">Data</th>
                                        <th style="padding: 12px 10px; text-align: center;">Hora</th>
                                        <th style="padding: 12px 10px; text-align: left;">Linha</th>
                                        <th style="padding: 12px 10px; text-align: left;">Veículo</th>
                                        <th style="padding: 12px 10px; text-align: center;">Viagem</th>
                                        <th style="padding: 12px 10px; text-align: right;">Embarques</th>
                                        <th style="padding: 12px 10px; text-align: right;">Desembarques</th>
                                        <th style="padding: 12px 10px; text-align: center;">Ocupação (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dadosTabelaLinhaVeiculoDataHora)): ?>
                                        <tr><td colspan="8" style="text-align: center; padding: 30px; color: #94a3b8;">Nenhum registro localizado para os filtros selecionados.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dadosTabelaLinhaVeiculoDataHora as $row): 
                                            $emb = (int)$row['embarques'];
                                            $des = (int)$row['desembarques'];
                                            $ocup = cbCalcularOcupacao($row);
                                        ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569;">
                                                    <?php echo htmlspecialchars($row['dataFormatada']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 700; color: #2563eb;">
                                                    <?php echo htmlspecialchars($row['horaFormatada']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;">
                                                    <?php echo htmlspecialchars($row['linhaNumero']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; font-weight: 600; color: #334155;">
                                                    <?php echo htmlspecialchars($row['veiculoPrefixo']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center;">
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; <?php echo (strtolower($row['sentido']) === 'volta') ? 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;' : 'background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;'; ?>">
                                                        <?php echo (strtolower($row['sentido']) === 'volta') ? '🔄 Volta' : '➡️ Ida'; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #15803d;">
                                                    <?php echo number_format($emb, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #be123c;">
                                                    <?php echo number_format($des, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 800; font-size: 0.95rem; color: #0f172a;">
                                                    <?php echo $ocup; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TABELA 2: TOTAL DE PASSAGEIROS POR LINHA -->
                    <div id="container-dash-t2" class="dash-table-card panel-card" style="margin-bottom: 24px; display: none;">
                        <div class="panel-card-header" style="background: #ffffff; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                    <span>🚍 Total de passageiros por linha</span>
                                </h3>
                                <p style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">Consolidado de embarques, desembarques e taxa de ocupação por linha</p>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <input type="text" oninput="filterTableRows('tbl-t2', this.value)" placeholder="Buscar data, linha, viagem..." style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; width: 220px; outline: none; background: #ffffff;">
                                <div class="export-dropdown-wrapper" style="position: relative; display: inline-block;">
                                    <button type="button" onclick="toggleExportMenu('export-menu-t2', event)" style="background: #0f172a; color: #ffffff; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(15,23,42,0.2);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        <span>Exportar</span>
                                    </button>
                                    <div id="export-menu-t2" class="export-menu-box" style="display: none; position: absolute; right: 0; top: 110%; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); min-width: 170px; z-index: 100; padding: 6px 0;">
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t2', 'pdf', 'Total_Passageiros_Por_Linha')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #be123c; font-weight: 700; text-decoration: none;">
                                            📄 Exportar para PDF
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t2', 'csv', 'Total_Passageiros_Por_Linha')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #15803d; font-weight: 700; text-decoration: none;">
                                            📊 Exportar para CSV
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t2', 'doc', 'Total_Passageiros_Por_Linha')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #1d4ed8; font-weight: 700; text-decoration: none;">
                                            📝 Exportar para DOC
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="overflow-x: auto; padding: 12px 20px 20px;">
                            <table class="apps-table" id="tbl-t2" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <th style="padding: 12px 10px; text-align: center;">Data</th>
                                        <th style="padding: 12px 10px; text-align: left;">Linha</th>
                                        <th style="padding: 12px 10px; text-align: center;">Viagem</th>
                                        <th style="padding: 12px 10px; text-align: right;">Embarques</th>
                                        <th style="padding: 12px 10px; text-align: right;">Desembarques</th>
                                        <th style="padding: 12px 10px; text-align: center;">Ocupação (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dadosTabelaTotalPorLinha)): ?>
                                        <tr><td colspan="6" style="text-align: center; padding: 30px; color: #94a3b8;">Nenhum registro localizado.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dadosTabelaTotalPorLinha as $row): 
                                            $emb = (int)$row['embarques'];
                                            $des = (int)$row['desembarques'];
                                            $ocup = cbCalcularOcupacao($row);
                                        ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569;">
                                                    <?php echo htmlspecialchars($row['dataFormatada']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;">
                                                    <?php echo htmlspecialchars($row['linhaNumero']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center;">
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; <?php echo (strtolower($row['sentido']) === 'volta') ? 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;' : 'background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;'; ?>">
                                                        <?php echo (strtolower($row['sentido']) === 'volta') ? '🔄 Volta' : '➡️ Ida'; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #15803d;">
                                                    <?php echo number_format($emb, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #be123c;">
                                                    <?php echo number_format($des, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 800; font-size: 0.95rem; color: #0f172a;">
                                                    <?php echo $ocup; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TABELA 3: EMBARQUES/ DESEMBARQUES / OCUPAÇÃO POR LOCAL -->
                    <div id="container-dash-t3" class="dash-table-card panel-card" style="margin-bottom: 24px; display: none;">
                        <div class="panel-card-header" style="background: #ffffff; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                    <span>📍 Embarques/ desembarques / Ocupação por LOCAL</span>
                                </h3>
                                <p style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">Fluxo de passageiros e ocupação agrupados por ponto ou estação</p>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <input type="text" oninput="filterTableRows('tbl-t3', this.value)" placeholder="Buscar local, viagem..." style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; width: 220px; outline: none; background: #ffffff;">
                                <div class="export-dropdown-wrapper" style="position: relative; display: inline-block;">
                                    <button type="button" onclick="toggleExportMenu('export-menu-t3', event)" style="background: #0f172a; color: #ffffff; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(15,23,42,0.2);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        <span>Exportar</span>
                                    </button>
                                    <div id="export-menu-t3" class="export-menu-box" style="display: none; position: absolute; right: 0; top: 110%; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); min-width: 170px; z-index: 100; padding: 6px 0;">
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t3', 'pdf', 'Embarques_Desembarques_Ocupacao_Por_Local')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #be123c; font-weight: 700; text-decoration: none;">
                                            📄 Exportar para PDF
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t3', 'csv', 'Embarques_Desembarques_Ocupacao_Por_Local')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #15803d; font-weight: 700; text-decoration: none;">
                                            📊 Exportar para CSV
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t3', 'doc', 'Embarques_Desembarques_Ocupacao_Por_Local')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #1d4ed8; font-weight: 700; text-decoration: none;">
                                            📝 Exportar para DOC
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="overflow-x: auto; padding: 12px 20px 20px;">
                            <table class="apps-table" id="tbl-t3" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <th style="padding: 12px 10px; text-align: left;">LOCAL</th>
                                        <th style="padding: 12px 10px; text-align: center;">Viagem</th>
                                        <th style="padding: 12px 10px; text-align: right;">Embarques</th>
                                        <th style="padding: 12px 10px; text-align: right;">Desembarques</th>
                                        <th style="padding: 12px 10px; text-align: center;">Ocupação (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dadosTabelaPorLocal)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">Nenhum registro localizado.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dadosTabelaPorLocal as $row): 
                                            $emb = (int)$row['embarques'];
                                            $des = (int)$row['desembarques'];
                                            $ocup = cbCalcularOcupacao($row);
                                        ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;">
                                                    <?php echo htmlspecialchars($row['nomeLocal']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center;">
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; <?php echo (strtolower($row['sentido']) === 'volta') ? 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;' : 'background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;'; ?>">
                                                        <?php echo (strtolower($row['sentido']) === 'volta') ? '🔄 Volta' : '➡️ Ida'; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #15803d;">
                                                    <?php echo number_format($emb, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #be123c;">
                                                    <?php echo number_format($des, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 800; font-size: 0.95rem; color: #0f172a;">
                                                    <?php echo $ocup; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TABELA 4: EMBARQUES/ DESEMBARQUES / OCUPAÇÃO POR EMPRESA/COOPERATIVA -->
                    <div id="container-dash-t4" class="dash-table-card panel-card" style="margin-bottom: 24px; display: none;">
                        <div class="panel-card-header" style="background: #ffffff; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                    <span>🏢 Embarques/ desembarques / Ocupação por EMPRESA/COOPERATIVA</span>
                                </h3>
                                <p style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">Consolidado de movimentação por operadora ou empresa de transporte</p>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <input type="text" oninput="filterTableRows('tbl-t4', this.value)" placeholder="Buscar empresa, viagem..." style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; width: 220px; outline: none; background: #ffffff;">
                                <div class="export-dropdown-wrapper" style="position: relative; display: inline-block;">
                                    <button type="button" onclick="toggleExportMenu('export-menu-t4', event)" style="background: #0f172a; color: #ffffff; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(15,23,42,0.2);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        <span>Exportar</span>
                                    </button>
                                    <div id="export-menu-t4" class="export-menu-box" style="display: none; position: absolute; right: 0; top: 110%; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); min-width: 170px; z-index: 100; padding: 6px 0;">
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t4', 'pdf', 'Embarques_Desembarques_Ocupacao_Por_Empresa')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #be123c; font-weight: 700; text-decoration: none;">
                                            📄 Exportar para PDF
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t4', 'csv', 'Embarques_Desembarques_Ocupacao_Por_Empresa')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #15803d; font-weight: 700; text-decoration: none;">
                                            📊 Exportar para CSV
                                        </a>
                                        <a href="javascript:void(0)" onclick="exportTable('tbl-t4', 'doc', 'Embarques_Desembarques_Ocupacao_Por_Empresa')" style="display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 0.83rem; color: #1d4ed8; font-weight: 700; text-decoration: none;">
                                            📝 Exportar para DOC
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="overflow-x: auto; padding: 12px 20px 20px;">
                            <table class="apps-table" id="tbl-t4" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <th style="padding: 12px 10px; text-align: left;">EMPRESA</th>
                                        <th style="padding: 12px 10px; text-align: center;">Viagem</th>
                                        <th style="padding: 12px 10px; text-align: right;">Embarques</th>
                                        <th style="padding: 12px 10px; text-align: right;">Desembarques</th>
                                        <th style="padding: 12px 10px; text-align: center;">Ocupação (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dadosTabelaPorEmpresa)): ?>
                                        <tr><td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">Nenhum registro localizado.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dadosTabelaPorEmpresa as $row): 
                                            $emb = (int)$row['embarques'];
                                            $des = (int)$row['desembarques'];
                                            $ocup = cbCalcularOcupacao($row);
                                        ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;">
                                                    <?php echo htmlspecialchars($row['nomeEmpresa']); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center;">
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; <?php echo (strtolower($row['sentido']) === 'volta') ? 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;' : 'background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;'; ?>">
                                                        <?php echo (strtolower($row['sentido']) === 'volta') ? '🔄 Volta' : '➡️ Ida'; ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #15803d;">
                                                    <?php echo number_format($emb, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: right; font-weight: 700; color: #be123c;">
                                                    <?php echo number_format($des, 0, ',', '.'); ?>
                                                </td>
                                                <td style="padding: 12px 10px; text-align: center; font-weight: 800; font-size: 0.95rem; color: #0f172a;">
                                                    <?php echo $ocup; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>


                </div>

                <?php if ($canViewSolicitacoes): ?>
                <!-- ABA 3: SOLICITAÇÕES DE CORREÇÃO (PARA ADMINISTRADOR E GERENTE) -->
                <div id="user-tab-solicitacoes" class="user-tab-content" style="display: none;">
                    
                    <!-- Header do Módulo de Solicitações -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <h2 style="font-size: 1.3rem; font-weight: 800; color: #0f172a; margin: 0 0 4px 0; display: flex; align-items: center; gap: 10px;">
                                <span style="background: #eff6ff; color: #2563eb; width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                                    </svg>
                                </span>
                                Solicitações de Correção de Contagem
                            </h2>
                            <p style="color: #64748b; font-size: 0.88rem; margin: 0; font-weight: 500;">
                                <?php if ($isAdmin): ?>
                                    Visão do Administrador: visualize e responda às solicitações de ajuste de contagem enviadas pelos pesquisadores.
                                <?php else: ?>
                                    Visão da Gerência: acompanhe e decida sobre as solicitações de ajuste de contagem sob sua supervisão.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div style="display: flex; gap: 10px; align-items: center;">
                            <a href="index.php?tab=solicitacoes" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: 9px 16px; border-radius: 10px; font-weight: 700; font-size: 0.86rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="16" height="16">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <span>Atualizar Lista</span>
                            </a>
                        </div>
                    </div>

                    <!-- KPI Cards das Solicitações -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
                        
                        <!-- Pendentes -->
                        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #b45309; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>⏳ Pendentes de Aprovação</span>
                            </div>
                            <div style="color: #b45309; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo $solicPendentesCount; ?>
                            </div>
                            <div style="color: #92400e; font-size: 0.78rem; font-weight: 600;">
                                Aguardando decisão do supervisor
                            </div>
                        </div>

                        <!-- Aprovadas -->
                        <div style="background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #15803d; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>✅ Aprovadas / Ajustadas</span>
                            </div>
                            <div style="color: #15803d; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo $solicAprovadasCount; ?>
                            </div>
                            <div style="color: #166534; font-size: 0.78rem; font-weight: 600;">
                                Descontadas na contagem
                            </div>
                        </div>

                        <!-- Rejeitadas -->
                        <div style="background: #ffe4e6; border: 1px solid #fecdd3; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #be123c; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>❌ Rejeitadas / Recusadas</span>
                            </div>
                            <div style="color: #be123c; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo $solicRejeitadasCount; ?>
                            </div>
                            <div style="color: #9f1239; font-size: 0.78rem; font-weight: 600;">
                                Mantidas sem alteração
                            </div>
                        </div>

                        <!-- Total -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 20px;">
                            <div style="color: #475569; font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span>📋 Total de Solicitações</span>
                            </div>
                            <div style="color: #0f172a; font-size: 1.85rem; font-weight: 800; margin: 8px 0 2px;">
                                <?php echo count($solicitacoesCorrecao); ?>
                            </div>
                            <div style="color: #64748b; font-size: 0.78rem; font-weight: 600;">
                                Registradas no sistema
                            </div>
                        </div>

                    </div>

                    <!-- Barra de Filtros e Busca Rápida -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                        
                        <!-- Pills de Status -->
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="solic-filter-pill active" data-status-filter="todos" onclick="filtrarSolicitacoesPorStatus('todos', this)" style="border: none; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.2s; background: #0f172a; color: #ffffff;">
                                Todas (<?php echo count($solicitacoesCorrecao); ?>)
                            </button>
                            <button type="button" class="solic-filter-pill" data-status-filter="pendente" onclick="filtrarSolicitacoesPorStatus('pendente', this)" style="border: 1px solid #fed7aa; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.2s; background: #fff7ed; color: #c2410c;">
                                ⏳ Pendentes (<?php echo $solicPendentesCount; ?>)
                            </button>
                            <button type="button" class="solic-filter-pill" data-status-filter="aprovada" onclick="filtrarSolicitacoesPorStatus('aprovada', this)" style="border: 1px solid #bbf7d0; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.2s; background: #f0fdf4; color: #15803d;">
                                ✅ Aprovadas (<?php echo $solicAprovadasCount; ?>)
                            </button>
                            <button type="button" class="solic-filter-pill" data-status-filter="rejeitada" onclick="filtrarSolicitacoesPorStatus('rejeitada', this)" style="border: 1px solid #fecdd3; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.2s; background: #fff1f2; color: #be123c;">
                                ❌ Rejeitadas (<?php echo $solicRejeitadasCount; ?>)
                            </button>
                        </div>

                        <!-- Campo de Busca em Tempo Real -->
                        <div style="flex: 1; max-width: 420px; min-width: 260px; position: relative;">
                            <input type="text" id="buscarSolicitacoesInput" oninput="buscarSolicitacoesTable(this.value)" placeholder="Buscar por funcionário, supervisor, justificativa..." style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.88rem; outline: none; background: #f8fafc; font-family: inherit;">
                        </div>

                    </div>

                    <!-- Tabela de Solicitações -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 16px rgba(15,23,42,0.02);">
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
                                <thead>
                                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em;">
                                        <th style="padding: 14px 16px; font-weight: 800;">#ID / Data</th>
                                        <th style="padding: 14px 16px; font-weight: 800;">Funcionário (Solicitante)</th>
                                        <th style="padding: 14px 16px; font-weight: 800;">Supervisor Destino</th>
                                        <th style="padding: 14px 16px; font-weight: 800;">Contagem</th>
                                        <th style="padding: 14px 16px; font-weight: 800; text-align: center;">Ajuste</th>
                                        <th style="padding: 14px 16px; font-weight: 800;">Justificativa</th>
                                        <th style="padding: 14px 16px; font-weight: 800; text-align: center;">Status</th>
                                        <th style="padding: 14px 16px; font-weight: 800;">Resposta / Decisão</th>
                                        <th style="padding: 14px 16px; font-weight: 800; text-align: center; min-width: 160px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="tabelaSolicitacoesBody">
                                    <?php if (empty($solicitacoesCorrecao)): ?>
                                        <tr id="solicitacoesNoDataRow">
                                            <td colspan="9" style="padding: 40px 20px; text-align: center; color: #94a3b8;">
                                                <div style="font-size: 2.5rem; margin-bottom: 10px;">📭</div>
                                                <div style="font-weight: 700; font-size: 1rem; color: #475569; margin-bottom: 4px;">Nenhuma solicitação encontrada</div>
                                                <div style="font-size: 0.84rem;">Quando um funcionário solicitar uma correção no meio da contagem, ela aparecerá aqui para o supervisor.</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($solicitacoesCorrecao as $sol): ?>
                                            <?php 
                                                $stLower = strtolower($sol['status']);
                                                $podeResponder = ($stLower === 'pendente') && ($isAdmin || $isGerente || (int)$sol['idUsuarioDestino'] === $usuarioId);
                                                $linhaTxt = !empty($sol['linhaNumero']) ? $sol['linhaNumero'] . ' - ' . $sol['linhaDescricao'] : (!empty($sol['idLinha']) ? 'Linha #' . $sol['idLinha'] : 'N/A');
                                                $veicTxt = !empty($sol['veiculoPrefixo']) ? $sol['veiculoPrefixo'] : (!empty($sol['idVeiculo']) ? 'Prefixo #' . $sol['idVeiculo'] : 'N/A');
                                            ?>
                                            <tr data-status="<?php echo htmlspecialchars($stLower); ?>" style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease;">
                                                
                                                <!-- ID e Data -->
                                                <td style="padding: 14px 16px; vertical-align: middle;">
                                                    <div style="font-weight: 800; color: #0f172a;">#<?php echo $sol['idSolicitacao']; ?></div>
                                                    <div style="color: #64748b; font-size: 0.78rem; font-weight: 600;"><?php echo htmlspecialchars($sol['dataSolicitacaoFmt']); ?></div>
                                                </td>

                                                <!-- Solicitante -->
                                                <td style="padding: 14px 16px; vertical-align: middle;">
                                                    <div style="font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                                        <span>👤 <?php echo htmlspecialchars($sol['solicitanteNome'] ?: 'Usuário #'.$sol['idUsuarioSolicitante']); ?></span>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.78rem;">@<?php echo htmlspecialchars($sol['solicitanteLogin'] ?: ''); ?></div>
                                                </td>

                                                <!-- Supervisor Destino -->
                                                <td style="padding: 14px 16px; vertical-align: middle;">
                                                    <div style="font-weight: 700; color: #1e3a8a; display: flex; align-items: center; gap: 6px;">
                                                        <span>🛡️ <?php echo htmlspecialchars($sol['destinoNome'] ?: 'Supervisor #'.$sol['idUsuarioDestino']); ?></span>
                                                    </div>
                                                    <div style="color: #64748b; font-size: 0.78rem;">@<?php echo htmlspecialchars($sol['destinoLogin'] ?: ''); ?></div>
                                                </td>

                                                <!-- Contagem -->
                                                <td style="padding: 14px 16px; vertical-align: middle;">
                                                    <div style="font-weight: 700; color: #0f172a;">Sessão #<?php echo $sol['idContagem']; ?></div>
                                                    <div style="color: #475569; font-size: 0.78rem;">Linha: <?php echo htmlspecialchars($linhaTxt); ?></div>
                                                    <div style="color: #64748b; font-size: 0.76rem;">Veículo: <?php echo htmlspecialchars($veicTxt); ?></div>
                                                </td>

                                                <!-- Quantidade de Ajuste -->
                                                <td style="padding: 14px 16px; vertical-align: middle; text-align: center;">
                                                    <span style="display: inline-block; background: #fee2e2; color: #dc2626; padding: 4px 10px; border-radius: 8px; font-weight: 800; font-size: 0.95rem; border: 1px solid #fca5a5;">
                                                        <?php if (!empty($sol['tipoContagem']) && stripos($sol['tipoContagem'], 'perc') !== false): ?>
                                                            -<?php echo calcPercentualStr((int)$sol['quantidade']); ?>
                                                            <span style="display: block; font-size: 0.72rem; font-weight: 600; opacity: 0.85;">(<?php echo (int)$sol['quantidade']; ?> pas.)</span>
                                                        <?php else: ?>
                                                            -<?php echo (int)$sol['quantidade']; ?> pas.
                                                        <?php endif; ?>
                                                    </span>
                                                </td>

                                                <!-- Justificativa do Fiscal -->
                                                <td style="padding: 14px 16px; vertical-align: middle; max-width: 240px;">
                                                    <div style="color: #334155; font-size: 0.86rem; line-height: 1.4; word-break: break-word;">
                                                        <?php echo nl2br(htmlspecialchars($sol['justificativa'])); ?>
                                                    </div>
                                                </td>

                                                <!-- Status -->
                                                <td style="padding: 14px 16px; vertical-align: middle; text-align: center;">
                                                    <?php if ($stLower === 'pendente'): ?>
                                                        <span style="display: inline-flex; align-items: center; gap: 5px; background: #fef3c7; color: #b45309; padding: 4px 10px; border-radius: 9999px; font-weight: 700; font-size: 0.78rem; border: 1px solid #fcd34d;">
                                                            <span style="width: 7px; height: 7px; background: #f59e0b; border-radius: 50%; display: inline-block;"></span>
                                                            Pendente
                                                        </span>
                                                    <?php elseif ($stLower === 'aprovada'): ?>
                                                        <span style="display: inline-flex; align-items: center; gap: 5px; background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 9999px; font-weight: 700; font-size: 0.78rem; border: 1px solid #86efac;">
                                                            ✓ Aprovada
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="display: inline-flex; align-items: center; gap: 5px; background: #fee2e2; color: #be123c; padding: 4px 10px; border-radius: 9999px; font-weight: 700; font-size: 0.78rem; border: 1px solid #fca5a5;">
                                                            ✕ Rejeitada
                                                        </span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Resposta / Decisão -->
                                                <td style="padding: 14px 16px; vertical-align: middle; font-size: 0.82rem; color: #475569;">
                                                    <?php if ($stLower === 'pendente'): ?>
                                                        <span style="color: #94a3b8; font-style: italic;">Aguardando supervisor...</span>
                                                    <?php else: ?>
                                                        <div style="font-weight: 600; color: #1e293b;">
                                                            Por: <?php echo htmlspecialchars($sol['respostaNome'] ?: 'Supervisor'); ?>
                                                        </div>
                                                        <div style="color: #64748b; font-size: 0.76rem;"><?php echo htmlspecialchars($sol['dataRespostaFmt'] ?: ''); ?></div>
                                                        <?php if (!empty($sol['motivoResposta'])): ?>
                                                            <div style="margin-top: 4px; font-size: 0.8rem; color: #0f172a; background: #f8fafc; padding: 4px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                                                "<?php echo htmlspecialchars($sol['motivoResposta']); ?>"
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Ações -->
                                                <td style="padding: 14px 16px; vertical-align: middle; text-align: center;">
                                                    <?php if ($podeResponder): ?>
                                                        <div style="display: flex; gap: 8px; justify-content: center;">
                                                            <button type="button" onclick="aprovarSolicitacao(<?php echo $sol['idSolicitacao']; ?>, <?php echo (int)$sol['quantidade']; ?>, '<?php echo addslashes(htmlspecialchars($sol['solicitanteNome'])); ?>', <?php echo $sol['idContagem']; ?>, '<?php echo !empty($sol['tipoContagem']) ? strtolower($sol['tipoContagem']) : 'quantidade'; ?>')" title="Aprovar e abater da contagem" style="background: #16a34a; color: #ffffff; border: none; padding: 7px 12px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22,163,74,0.3); transition: all 0.2s;">
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="14" height="14">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                                </svg>
                                                                <span>Aprovar</span>
                                                            </button>

                                                            <button type="button" onclick="abrirModalRejeitar(<?php echo $sol['idSolicitacao']; ?>, '<?php echo addslashes(htmlspecialchars($sol['solicitanteNome'])); ?>')" title="Rejeitar solicitação" style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 7px 12px; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s;">
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="14" height="14">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                                </svg>
                                                                <span>Rejeitar</span>
                                                            </button>
                                                        </div>
                                                    <?php elseif ($stLower === 'pendente'): ?>
                                                        <span style="font-size: 0.8rem; color: #d97706; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                            <span>⏳ Em análise</span>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.8rem; color: #64748b; font-weight: 600;">
                                                            Concluída
                                                        </span>
                                                    <?php endif; ?>
                                                </td>

                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- MODAL DE SELEÇÃO MÚLTIPLA DE VEÍCULOS COM JANELA EXTERNA AO LADO -->
    <div class="modal-overlay" id="modalSelecaoVeiculos" style="display: none; align-items: center; justify-content: center; z-index: 99999; padding: 16px;">
        
        <!-- Wrapper que posiciona o Modal de Registro e a Janela de Escolhidos lado a lado -->
        <div style="display: flex; gap: 16px; align-items: flex-start; justify-content: center; max-width: 940px; width: 100%; flex-wrap: wrap;">

            <!-- JANELA 1: MODAL DE REGISTRO E PESQUISA DE VEÍCULOS -->
            <div class="modal-card" style="flex: 1; min-width: 320px; max-width: 580px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; padding: 0; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35); background: #ffffff;">
                
                <!-- Header do Modal de Pesquisa -->
                <div class="modal-header" style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; margin-bottom: 0;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            🚌
                        </div>
                        <div>
                            <div style="font-size: 1.05rem; font-weight: 700; color: #0f172a;">Pesquisar Veículos</div>
                            <div style="font-size: 0.8rem; color: #64748b;">Marque quantos veículos desejar para a contagem</div>
                        </div>
                    </div>
                    <button type="button" onclick="closeModalVeiculos()" style="background: none; border: none; font-size: 1.6rem; color: #94a3b8; cursor: pointer; padding: 4px; line-height: 1;">&times;</button>
                </div>

                <!-- Campo de Busca em Tempo Real -->
                <div style="padding: 14px 20px 10px; border-bottom: 1px solid #f1f5f9; background: #ffffff;">
                    <div style="position: relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8;">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="inputBuscaVeiculoModal" placeholder="Digite o prefixo ou placa do veículo..." oninput="filtrarVeiculosModal(this.value)" style="width: 100%; padding: 10px 14px 10px 40px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 0.92rem; outline: none; box-sizing: border-box; transition: all 0.2s;">
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; font-size: 0.8rem; color: #64748b; flex-wrap: wrap; gap: 6px;">
                        <span>Selecione os veículos da frota abaixo:</span>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button type="button" onclick="cadastrarNovoVeiculoModalInline()" style="background: none; border: none; color: #16a34a; font-size: 0.8rem; font-weight: 700; cursor: pointer; text-decoration: underline;">+ Cadastrar Novo Ônibus</button>
                            <button type="button" onclick="desmarcarTodosVeiculosModal()" style="background: none; border: none; color: #dc2626; font-size: 0.8rem; font-weight: 600; cursor: pointer; text-decoration: underline;">Desmarcar todos</button>
                        </div>
                    </div>
                </div>

                <!-- Lista Rolável de Veículos para Seleção -->
                <div id="lista-veiculos-modal" style="padding: 12px 20px; overflow-y: auto; flex: 1; max-height: 380px; display: flex; flex-direction: column; gap: 8px; background: #ffffff;">
                    <?php foreach ($veiculosDisponiveis as $v): ?>
                        <?php 
                            $pStr = htmlspecialchars($v['prefixo']);
                            $plStr = !empty($v['placa']) ? htmlspecialchars($v['placa']) : '';
                            $buscaData = strtolower($v['prefixo'] . ' ' . $plStr);
                        ?>
                        <label class="item-veiculo-modal" data-id="<?php echo $v['idVeiculo']; ?>" data-prefixo="<?php echo $pStr; ?>" data-placa="<?php echo $plStr; ?>" data-search="<?php echo htmlspecialchars($buscaData); ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.15s; background: #ffffff; user-select: none;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <input type="checkbox" class="chk-veiculo-modal" value="<?php echo $v['idVeiculo']; ?>" data-prefixo="<?php echo $pStr; ?>" data-placa="<?php echo $plStr; ?>" onchange="atualizarItemVeiculoVisual(this)" style="width: 19px; height: 19px; accent-color: #2563eb; cursor: pointer;">
                                <div>
                                    <div style="font-weight: 700; font-size: 0.92rem; color: #1e293b;">
                                        Prefixo: <?php echo $pStr; ?>
                                    </div>
                                    <?php if ($plStr): ?>
                                        <div style="font-size: 0.78rem; color: #64748b;">
                                            Placa: <?php echo $plStr; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="badge-veiculo-checked" style="display: none; font-size: 0.75rem; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 3px 8px; border-radius: 6px; border: 1px solid #bfdbfe;">
                                ✓ Selecionado
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <div id="aviso-veiculos-nenhum" style="display: none; text-align: center; padding: 30px 10px; color: #94a3b8; font-size: 0.88rem;">
                        Nenhum veículo encontrado correspondente à pesquisa.
                    </div>
                </div>

                <!-- Rodapé do Modal Principal -->
                <div style="padding: 14px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; background: #f8fafc;">
                    <button type="button" onclick="closeModalVeiculos()" style="padding: 9px 18px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        Cancelar
                    </button>
                    <button type="button" onclick="confirmarSelecaoVeiculosModal()" style="padding: 9px 20px; background: #2563eb; color: #ffffff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 6px rgba(37,99,235,0.3); transition: all 0.2s;">
                        Confirmar Seleção
                    </button>
                </div>
            </div>

            <!-- JANELA 2: JANELA EXTERNA AO LADO DO MODAL COM OS VEÍCULOS ESCOLHIDOS -->
            <div id="janela-externa-veiculos-selecionados" style="width: 300px; max-height: 85vh; background: #ffffff; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35); display: flex; flex-direction: column; overflow: hidden; border: 1.5px solid #bfdbfe; flex-shrink: 0;">
                
                <!-- Cabeçalho da Janela Externa -->
                <div style="padding: 16px 18px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 1.25rem;">📋</span>
                        <div>
                            <div style="font-weight: 800; font-size: 0.94rem; color: #1e3a8a;">Veículos Escolhidos</div>
                            <div style="font-size: 0.74rem; color: #3b82f6;">Janela de conferência em tempo real</div>
                        </div>
                    </div>
                    <span id="badge-lateral-qtd" style="background: #2563eb; color: #ffffff; padding: 3px 9px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; box-shadow: 0 1px 3px rgba(37,99,235,0.3);">0</span>
                </div>

                <!-- Lista dos Veículos Escolhidos na Janela Externa -->
                <div id="lista-veiculos-selecionados-lateral" style="padding: 12px 14px; overflow-y: auto; flex: 1; max-height: 380px; display: flex; flex-direction: column; gap: 8px; background: #f8fafc;">
                    <div id="vazio-lateral-hint" style="text-align: center; padding: 40px 15px; color: #94a3b8; font-size: 0.84rem;">
                        <div style="font-size: 2.2rem; margin-bottom: 8px; opacity: 0.6;">📋</div>
                        <strong style="color: #64748b;">Nenhum veículo escolhido</strong>
                        <p style="margin: 6px 0 0; font-size: 0.78rem; color: #94a3b8; line-height: 1.4;">
                            Marque os veículos no modal de pesquisa ao lado para vê-los reunidos aqui fora.
                        </p>
                    </div>
                </div>

                <!-- Rodapé da Janela Externa -->
                <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background: #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                    <button type="button" onclick="desmarcarTodosVeiculosModal()" style="background: none; border: none; color: #dc2626; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-decoration: underline;">Limpar Seleção</button>
                    <span id="txt-modal-veiculos-selecionados" style="font-size: 0.78rem; font-weight: 700; color: #2563eb;">0 escolhido(s)</span>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL DE ALTERAR LINHA, VEÍCULO E TURNO DURANTE A CONTAGEM -->
    <div class="modal-overlay" id="alterarLinhaVeiculoModal">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22" style="color: #2563eb;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                    </svg>
                    Alterar Dados da Sessão (Veículo e Turno)
                </div>
                <button type="button" onclick="closeAlterarLinhaVeiculoModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
            </div>

            <form id="alterarLinhaVeiculoForm" onsubmit="submitAlterarLinhaVeiculo(event)">
                <input type="hidden" id="editIdLinha" value="<?php echo ($sessaoAtiva ? $sessaoAtiva['idLinha'] : ''); ?>">

                <div style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #334155; display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span>Veículo (Prefixo) <span style="color: #dc2626;">*</span>:</span>
                    </label>
                    <select id="editIdVeiculo" required class="form-select-custom cb-searchable-select" data-maxlength="5" data-placeholder="Digite o prefixo do veículo (até 5 dígitos)...">
                        <option value="">-- Selecione o Veículo --</option>
                        <?php foreach ($veiculosDisponiveis as $v): ?>
                            <option value="<?php echo $v['idVeiculo']; ?>" <?php echo ($sessaoAtiva && $sessaoAtiva['idVeiculo'] == $v['idVeiculo']) ? 'selected' : ''; ?>>
                                Prefixo: <?php echo htmlspecialchars($v['prefixo']); ?> <?php echo !empty($v['placa']) ? '('.htmlspecialchars($v['placa']).')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #334155; display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span>Turno da Sessão <span style="color: #dc2626;">*</span>:</span>
                    </label>
                    <select id="editTurno" required class="form-select-custom" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-weight: 700; font-size: 0.92rem; color: #000000; background: #ffffff; cursor: pointer; outline: none;">
                        <option value="Matutino" <?php echo ($sessaoAtiva && strcasecmp($sessaoAtiva['turno'], 'Matutino') === 0) ? 'selected' : ''; ?>>Matutino</option>
                        <option value="Vespertino" <?php echo ($sessaoAtiva && strcasecmp($sessaoAtiva['turno'], 'Vespertino') === 0) ? 'selected' : ''; ?>>Vespertino</option>
                        <option value="Noturno" <?php echo ($sessaoAtiva && strcasecmp($sessaoAtiva['turno'], 'Noturno') === 0) ? 'selected' : ''; ?>>Noturno</option>
                    </select>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeAlterarLinhaVeiculoModal()" style="padding: 10px 18px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        Cancelar
                    </button>
                    <button type="submit" style="padding: 10px 18px; background: #2563eb; color: #ffffff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                        Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE SOLICITAÇÃO DE CORREÇÃO COM ESCOLHA DE SUPERVISOR E JUSTIFICATIVA -->
    <div class="modal-overlay" id="correctionModal">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22" style="color: #ea580c;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    Solicitar Correção de Contagem
                </div>
                <button type="button" onclick="closeCorrectionModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
            </div>

            <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; font-size: 0.86rem; color: #9a3412; display: flex; align-items: flex-start; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18" style="flex-shrink: 0; margin-top: 2px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                <span>Esta correção será enviada como uma solicitação para aprovação do supervisor/gerente selecionado abaixo.</span>
            </div>

            <form id="correctionForm" onsubmit="submitCorrection(event)">
                <!-- 1. ESCOLHER SUPERVISOR / GERENTE DESTINATÁRIO -->
                <div style="margin-bottom: 16px;">
                    <label style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 6px;">
                        Enviar solicitação para quem? <span style="color: #dc2626;">* (Obrigatório)</span>
                    </label>
                    <select id="corrDestino" required class="form-select-custom" style="width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.95rem; font-weight: 600; color: #1e293b; background-color: #ffffff;">
                        <option value="">-- Selecione o Supervisor / Gerente --</option>
                        <?php foreach ($supervisoresDisponiveis as $sup): ?>
                            <?php 
                                $supCargo = isset($sup['cargo']) && !empty($sup['cargo']) ? $sup['cargo'] : '';
                                $supRoleTag = '';
                                if ((int)$sup['acesso'] === 1 || strcasecmp($supCargo, 'administrador') === 0) {
                                    $supRoleTag = '— [Administrador]';
                                } elseif ((int)$sup['acesso'] === 2 || strcasecmp($supCargo, 'gerente') === 0) {
                                    $supRoleTag = '— [Gerente]';
                                }
                            ?>
                            <option value="<?php echo $sup['idusuarios']; ?>">
                                👤 <?php echo htmlspecialchars($sup['nome']); ?> (@<?php echo htmlspecialchars($sup['login']); ?>) <?php echo $supRoleTag; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2A. QUANTIDADE A SUBTRAIR (MODO QUANTIDADE PADRÃO) -->
                <div id="containerCorrQtd" style="margin-bottom: 16px;">
                    <label style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 6px;">
                        Quantidade a Subtrair / Estornar: <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="number" id="corrQtd" min="1" max="1000" value="1" required style="width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 1.1rem; font-weight: 700;">
                </div>

                <!-- 2B. PORCENTAGEM A SUBTRAIR (MODO PERCENTUAL) -->
                <div id="containerCorrPercent" style="margin-bottom: 18px; display: none;">
                    <label style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 8px;">
                        Porcentagem a Subtrair / Estornar: <span style="color: #dc2626;">*</span>
                    </label>
                    
                    <!-- Botoeira de Seleção Rápida de % -->
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 10px;">
                        <button type="button" class="btn-corr-pct" onclick="selectCorrPercent(15)" data-pct="15" style="border: 2px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 10px 6px; border-radius: 10px; font-weight: 800; font-size: 1.05rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            15%
                        </button>
                        <button type="button" class="btn-corr-pct" onclick="selectCorrPercent(35)" data-pct="35" style="border: 2px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 10px 6px; border-radius: 10px; font-weight: 800; font-size: 1.05rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            35%
                        </button>
                        <button type="button" class="btn-corr-pct" onclick="selectCorrPercent(50)" data-pct="50" style="border: 2px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 10px 6px; border-radius: 10px; font-weight: 800; font-size: 1.05rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            50%
                        </button>
                        <button type="button" class="btn-corr-pct" onclick="selectCorrPercent(65)" data-pct="65" style="border: 2px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 10px 6px; border-radius: 10px; font-weight: 800; font-size: 1.05rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            65%
                        </button>
                        <button type="button" class="btn-corr-pct" onclick="selectCorrPercent(100)" data-pct="100" style="border: 2px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 10px 6px; border-radius: 10px; font-weight: 800; font-size: 1.05rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            100%
                        </button>
                        <button type="button" class="btn-corr-pct" onclick="selectCorrPercent(120)" data-pct="120" style="border: 2px solid #cbd5e1; background: #f8fafc; color: #334155; padding: 10px 6px; border-radius: 10px; font-weight: 800; font-size: 1.05rem; cursor: pointer; text-align: center; transition: all 0.2s;">
                            120%
                        </button>
                    </div>

                    <select id="corrPercentSelect" onchange="selectCorrPercent(this.value)" class="form-select-custom" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.95rem; font-weight: 700; color: #6b21a8; background-color: #faf5ff;">
                        <option value="15">15% de ocupação</option>
                        <option value="35">35% de ocupação</option>
                        <option value="50">50% de ocupação</option>
                        <option value="65">65% de ocupação</option>
                        <option value="100">100% de ocupação</option>
                        <option value="120">120% de ocupação</option>
                    </select>

                    <div id="corrPercentFeedback" style="margin-top: 8px; font-size: 0.84rem; color: #7e22ce; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                        <span>✓ Valor selecionado para estorno: <strong id="corrPercentBadge">15%</strong></span>
                    </div>
                </div>

                <!-- 3. JUSTIFICATIVA -->
                <div style="margin-bottom: 24px;">
                    <label style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 6px;">
                        Justificativa do Ajuste <span style="color: #dc2626;">* (Obrigatória)</span>:
                    </label>
                    <textarea id="corrJustificativa" rows="3" placeholder="Descreva claramente o motivo da correção (ex: Clique acidental de grupo, passageiros contabilizados a mais, etc.)..." required style="width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.92rem; resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeCorrectionModal()" style="padding: 10px 18px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitCorrecao" style="padding: 10px 20px; background: #ea580c; color: #ffffff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(234,88,12,0.3);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                        Enviar Solicitação ao Supervisor
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL PARA REJEITAR SOLICITAÇÃO COM MOTIVO -->
    <div class="modal-overlay" id="rejeitarSolicitacaoModal">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22" style="color: #dc2626;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Rejeitar Solicitação de Correção <span id="rejeitarModalId" style="color: #dc2626;">#--</span>
                </div>
                <button type="button" onclick="fecharModalRejeitar()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
            </div>

            <form id="rejeitarSolicitacaoForm" onsubmit="confirmarRejeicao(event)">
                <input type="hidden" id="rejeitarIdSolicitacao" value="">
                
                <div style="margin-bottom: 20px;">
                    <label style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 6px;">
                        Motivo da Recusa <span style="color: #dc2626;">* (Obrigatório)</span>:
                    </label>
                    <textarea id="rejeitarMotivo" rows="3" placeholder="Informe o motivo pelo qual esta solicitação não foi aprovada..." required style="width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.92rem; resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="fecharModalRejeitar()" style="padding: 10px 18px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 10px; font-weight: 600; cursor: pointer;">
                        Cancelar
                    </button>
                    <button type="submit" id="btnConfirmarRejeicao" style="padding: 10px 18px; background: #dc2626; color: #ffffff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                        Confirmar Rejeição
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE EXTRATO DETALHADO DO HISTÓRICO DE UMA SESSÃO -->
    <div class="modal-overlay" id="historyDetailsModal">
        <div class="modal-card" style="max-width: 1200px; width: 95vw; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22" style="color: #0054A6;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m0 12.75h7.5m-7.5 3h7.5M6 20.25a2.25 2.25 0 0 1-2.25-2.25V6c0-1.242 1.008-2.25 2.25-2.25h4.125c.621 0 1.125.504 1.125 1.125V6.75A2.25 2.25 0 0 0 13.5 9h2.25c.621 0 1.125.504 1.125 1.125v8.625A2.25 2.25 0 0 1 14.625 21H6Z" />
                    </svg>
                    Extrato Detalhado da Contagem <span id="histDetailId" style="color: #0054A6;">#--</span>
                </div>
                <button type="button" onclick="closeHistoryDetailsModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
            </div>

            <div id="histModalContent">
                <div style="text-align: center; padding: 30px; color: #64748b;">Carregando detalhes...</div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <button type="button" onclick="closeHistoryDetailsModal()" style="padding: 10px 20px; background: #0f172a; color: #ffffff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                    Fechar Extrato
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DE VISÃO EXPANDIDA DA TABELA DE REGISTROS DA SESSÃO -->
    <div class="modal-overlay" id="expandLogsModal">
        <div class="modal-card" style="max-width: 1380px; width: 96vw; max-height: 94vh; display: flex; flex-direction: column; overflow: hidden; padding: 24px;">
            <div class="modal-header" style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <div class="modal-title" style="display: flex; align-items: center; gap: 10px; font-size: 1.15rem; font-weight: 700; color: #0f172a;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22" style="color: #2563eb;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                    </svg>
                    <span>Registros Recentes desta Sessão — Visão Expandida</span>
                </div>
                <button type="button" onclick="closeExpandLogsModal()" style="background: none; border: none; font-size: 1.6rem; color: #94a3b8; cursor: pointer; line-height: 1;">&times;</button>
            </div>

            <!-- FILTRO / BUSCA RÁPIDA NO MODAL EXPANDIDO -->
            <div style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <div style="position: relative; flex: 1; min-width: 240px;">
                    <input type="text" id="expandLogSearch" onkeyup="filterExpandLogsTable(this.value)" placeholder="🔍 Pesquisar por horário, operação, justificativa ou características..." style="width: 100%; padding: 10px 14px 10px 38px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.88rem; outline: none; transition: all 0.2s;" onfocus="this.style.borderColor='#2563eb';" onblur="this.style.borderColor='#cbd5e1';">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
                <button type="button" onclick="salvarContagemAtiva()" style="background: #2563eb; color: #ffffff; border: none; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="16" height="16">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c.11.128.19.278.234.442l.7 2.622A2.25 2.25 0 0 1 16.34 9H7.66a2.25 2.25 0 0 1-2.187-2.616l.7-2.622a2.25 2.25 0 0 1 .476-.884l1.5-1.5A2.25 2.25 0 0 1 9.75 1h4.5c.597 0 1.17.238 1.591.659l1.752 1.663zM12 12a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                    </svg>
                    <span>Salvar Agora</span>
                </button>
            </div>

            <!-- CONTÊINER DA TABELA EXPANDIDA -->
            <div style="flex: 1; overflow-y: auto; overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.92rem;">
                    <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                        <tr style="border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">
                            <th style="padding: 14px 16px; width: 110px;">Horário</th>
                            <th style="padding: 14px 16px; width: 140px;">Operação</th>
                            <th style="padding: 14px 16px; width: 130px;">Quantidade</th>
                            <th style="padding: 14px 16px;">Observação / Status</th>
                        </tr>
                    </thead>
                    <tbody id="expandLogsTableBody">
                        <?php if (count($registrosSessao) === 0): ?>
                            <tr>
                                <td colspan="4" style="padding: 32px; text-align: center; color: #94a3b8;">
                                    Nenhum registro efetuado ainda nesta sessão.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registrosSessao as $reg): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 14px 16px; font-weight: 600; color: #475569; white-space: nowrap;">
                                        <?php echo htmlspecialchars($reg['horaFormatada']); ?>
                                    </td>
                                    <td style="padding: 14px 16px;">
                                        <?php if ($reg['tipo'] === 'incremento'): ?>
                                            <span class="badge-reg badge-inc" style="font-size: 0.85rem; padding: 6px 12px;">+ Entrada</span>
                                        <?php elseif ($reg['tipo'] === 'salvamento'): ?>
                                            <span class="badge-reg badge-salvo" style="font-size: 0.85rem; padding: 6px 12px;">💾 Salvo</span>
                                        <?php else: ?>
                                            <span class="badge-reg badge-corr" style="font-size: 0.85rem; padding: 6px 12px;">- Correção</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 14px 16px; font-weight: 800; font-size: 1.1rem; color: <?php echo $reg['tipo'] === 'salvamento' ? '#2563eb' : ($reg['quantidade'] > 0 ? '#16a34a' : '#dc2626'); ?>;">
                                        <?php 
                                            if ($reg['tipo'] === 'salvamento') {
                                                echo (isset($tipoCont) && stripos($tipoCont, 'perc') !== false) 
                                                    ? calcPercentualStr($reg['quantidade']) . ' (' . (int)$reg['quantidade'] . ' pas.)' 
                                                    : (int)$reg['quantidade'] . ' pas.';
                                            } else {
                                                echo (($reg['quantidade'] > 0 ? '+' : '') . (int)$reg['quantidade']);
                                            }
                                        ?>
                                    </td>
                                    <td style="padding: 14px 16px; color: #1e293b; line-height: 1.5; font-size: 0.92rem;">
                                        <?php 
                                            if ($reg['tipo'] === 'salvamento' || (!empty($reg['justificativa']) && strpos($reg['justificativa'], 'Operação Salva.') === 0)) {
                                                echo '<span style="color: #15803d; font-weight: 600;">✓ Contagem salva</span>';
                                            } elseif (!empty($reg['justificativa'])) {
                                                echo htmlspecialchars($reg['justificativa']);
                                            } else {
                                                echo '<em style="color:#a1a1aa;">Contagem direta</em>';
                                            }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid #e2e8f0; font-size: 0.85rem; color: #64748b;">
                <div>💡 Esta visão expandida permite visualizar todas as informações e justificativas longas sem cortes.</div>
                <button type="button" onclick="closeExpandLogsModal()" style="padding: 10px 22px; background: #0f172a; color: #ffffff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                    Fechar Visão Expandida
                </button>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT EM TEMPO REAL, NAVEGAÇÃO DE ABAS E AJAX -->
    <script>
        // 1. RELÓGIO EM TEMPO REAL E OPÇÃO DE EXIBIR DATA
        let showDateInClock = false;
        
        function toggleDateInClock() {
            showDateInClock = !showDateInClock;
            const btn = document.getElementById('btn-toggle-date');
            if (btn) {
                btn.textContent = showDateInClock ? '📅 Data: ON' : '📅 Data: OFF';
                btn.style.background = showDateInClock ? '#f1f5f9' : '#fee2e2';
                btn.style.color = showDateInClock ? '#334155' : '#991b1b';
                btn.style.borderColor = showDateInClock ? '#cbd5e1' : '#fecaca';
            }
            updateClock();
        }

        function updateClock() {
            const now = new Date();
            const day = String(now.getDate()).padStart(2, '0');
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');

            const clockEl = document.getElementById('realtime-clock');
            if (clockEl) {
                if (showDateInClock) {
                    clockEl.textContent = `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
                } else {
                    clockEl.textContent = `${hours}:${minutes}:${seconds}`;
                }
            }

            const panelDateEl = document.getElementById('panel-read-date');
            if (panelDateEl) {
                panelDateEl.textContent = `${day}/${month}/${year}`;
            }

            const panelTimeEl = document.getElementById('panel-read-time');
            if (panelTimeEl) {
                panelTimeEl.textContent = `${hours}:${minutes}:${seconds}`;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Variáveis da sessão ativa para chamadas AJAX
        const activeContagemId = <?php echo ($sessaoAtiva ? (int)$sessaoAtiva['idContagem'] : 0); ?>;
        const activeTipoContagem = '<?php echo ($sessaoAtiva && !empty($sessaoAtiva['tipoContagem'])) ? strtolower(trim($sessaoAtiva['tipoContagem'])) : 'quantidade'; ?>';

        // Salvar progresso da contagem ativa via AJAX (Manter sessão aberta)
        function salvarContagemAtiva() {
            if (!activeContagemId) return;

            const btns = document.querySelectorAll('.btn-save-action, #btn-salvar-contagem, #btn-salvar-contagem-grid');
            btns.forEach(b => {
                b.disabled = true;
                b.style.opacity = '0.7';
            });

            const formData = new FormData();
            formData.append('action', 'salvar_contagem');
            formData.append('idContagem', activeContagemId);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btns.forEach(b => {
                    b.disabled = false;
                    b.style.opacity = '1';
                });
                if (data.success) {
                    showToastNotification(data.message || '✓ Contagem salva com sucesso!');
                    if (data.logs) renderLogs(data.logs);
                } else {
                    alert(data.message || 'Por favor, selecione um ônibus antes de salvar a contagem.');
                    const busSec = document.querySelector('.onibus-selection-container');
                    if (busSec) {
                        busSec.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(err => {
                btns.forEach(b => {
                    b.disabled = false;
                    b.style.opacity = '1';
                });
                console.error('Erro ao salvar contagem:', err);
            });
        }

        // Seleção rápida de Ônibus para direcionar a contagem
        function selecionarOnibusContagem(idVeiculo, prefixo) {
            if (!activeContagemId || !idVeiculo) return;

            const btnOpt = document.getElementById('btn-veiculo-opt-' + idVeiculo);
            if (btnOpt) {
                btnOpt.style.opacity = '0.6';
            }

            const formData = new FormData();
            formData.append('action', 'alterar_linha_veiculo');
            formData.append('idContagem', activeContagemId);
            formData.append('idVeiculo', idVeiculo);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (btnOpt) btnOpt.style.opacity = '1';
                if (data.success) {
                    // Atualiza estados dos botões de ônibus
                    document.querySelectorAll('.btn-opcao-onibus').forEach(btn => {
                        btn.classList.remove('onibus-ativo');
                        btn.style.border = '2px solid #cbd5e1';
                        btn.style.background = '#ffffff';
                        const badge = btn.querySelector('.badge-onibus-status');
                        if (badge) badge.style.display = 'none';
                        const prefSpan = btn.querySelector('span');
                        if (prefSpan) prefSpan.style.color = '#0f172a';
                    });

                    if (btnOpt) {
                        btnOpt.classList.add('onibus-ativo');
                        btnOpt.style.border = '2px solid #2563eb';
                        btnOpt.style.background = '#eff6ff';
                        const badge = btnOpt.querySelector('.badge-onibus-status');
                        if (badge) badge.style.display = 'inline-block';
                        const prefSpan = btnOpt.querySelector('span');
                        if (prefSpan) prefSpan.style.color = '#1d4ed8';
                    }

                    // Atualiza badges e textos na tela
                    const badgeV = document.getElementById('badge-veiculo-ativo-mini');
                    if (badgeV) badgeV.textContent = 'Ônibus: ' + (data.veiculoText || prefixo);

                    const sessV = document.getElementById('session-veiculo-text');
                    if (sessV) sessV.textContent = data.veiculoText || prefixo;

                    const editV = document.getElementById('editIdVeiculo');
                    if (editV) editV.value = idVeiculo;

                    showToastNotification('✓ Ônibus ' + prefixo + ' selecionado para a contagem!');
                } else {
                    alert(data.message || 'Erro ao selecionar o veículo.');
                }
            })
            .catch(err => {
                if (btnOpt) btnOpt.style.opacity = '1';
                console.error('Erro ao selecionar ônibus:', err);
            });
        }

        // Seleção rápida de Linha para direcionar a contagem
        function selecionarLinhaContagem(idLinha, textoLinha) {
            if (!activeContagemId) return;

            const formData = new FormData();
            formData.append('action', 'alterar_linha_veiculo');
            formData.append('idContagem', activeContagemId);
            formData.append('idLinha', idLinha);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const badgeL = document.getElementById('badge-linha-ativa-mini');
                    if (badgeL) badgeL.textContent = 'Linha: ' + (data.linhaText || textoLinha);

                    const sessL = document.getElementById('session-linha-text');
                    if (sessL) sessL.textContent = data.linhaText || textoLinha;

                    const editL = document.getElementById('editIdLinha');
                    if (editL) editL.value = idLinha;

                    showToastNotification('✓ Linha atualizada para a contagem!');
                } else {
                    alert(data.message || 'Erro ao alterar a linha.');
                }
            })
            .catch(err => console.error('Erro ao alterar linha:', err));
        }

        // Filtro em tempo real na grade de ônibus
        function filtrarOnibusGrid(termo) {
            termo = (termo || '').toLowerCase().trim();
            const btns = document.querySelectorAll('#grid-onibus-registrados .btn-opcao-onibus');
            let visiveis = 0;
            btns.forEach(btn => {
                const search = btn.getAttribute('data-search') || '';
                if (!termo || search.indexOf(termo) !== -1) {
                    btn.style.display = 'flex';
                    visiveis++;
                } else {
                    btn.style.display = 'none';
                }
            });

            const aviso = document.getElementById('aviso-onibus-nao-encontrado');
            if (aviso) {
                aviso.style.display = (visiveis === 0) ? 'block' : 'none';
            }
        }

        let toastTimeoutId = null;

        function showToastNotification(msg, duration = 2000) {
            let toast = document.getElementById('global-toast-notification');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'global-toast-notification';
                toast.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 9999; background: #15803d; color: #ffffff; padding: 14px 20px; border-radius: 12px; font-weight: 700; font-size: 0.92rem; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 8px; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); transform: translateY(100px); opacity: 0;';
                document.body.appendChild(toast);
            }
            if (toastTimeoutId) {
                clearTimeout(toastTimeoutId);
            }
            toast.textContent = msg;
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';

            toastTimeoutId = setTimeout(() => {
                toast.style.transform = 'translateY(100px)';
                toast.style.opacity = '0';
            }, duration);
        }

        // 2. ALTERNÂNCIA DE ABAS DO USUÁRIO (Contador vs Meu Histórico)
        function switchUserTab(tabId, btn) {
            const tabs = document.querySelectorAll('.user-tab-content');
            tabs.forEach(t => t.style.display = 'none');

            const target = document.getElementById('user-' + tabId);
            if (target) target.style.display = 'block';

            const btns = document.querySelectorAll('.tabs-navigation .tab-btn');
            btns.forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            if (tabId === 'tab-contador') {
                if (typeof limparTodasSelecoesObrigatorias === 'function') {
                    limparTodasSelecoesObrigatorias();
                }
            }
        }

        // 3. PAGINAÇÃO, FILTRO DE BUSCA E DESTAQUE (HIGHLIGHT) NA TABELA DE HISTÓRICO
        let historyCurrentPage = 1;
        let historyPageSize = 10;

        function getHistoryTableRows() {
            const tbody = document.querySelector('#userHistoryTable tbody');
            if (!tbody) return [];
            const allRows = Array.from(tbody.querySelectorAll('tr'));
            return allRows.filter(row => row.cells.length > 1 && row.id !== 'userHistoryNoDataRow');
        }

        function filterUserHistoryTable(query) {
            historyCurrentPage = 1;
            renderHistoryPage(query);
        }

        function changeHistoryPageSize(newSize) {
            historyPageSize = parseInt(newSize) || 10;
            historyCurrentPage = 1;
            renderHistoryPage();
        }

        function goToHistoryPage(page) {
            historyCurrentPage = page;
            renderHistoryPage();
            const tableCard = document.querySelector('#userHistoryTable');
            if (tableCard) {
                tableCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function renderHistoryPage(query) {
            if (query === undefined) {
                const searchInput = document.getElementById('search-history-input');
                query = searchInput ? searchInput.value : '';
            }
            const rawQuery = query ? query.trim() : '';
            const qLower = rawQuery.toLowerCase();

            const rows = getHistoryTableRows();
            const noDataRow = document.getElementById('userHistoryNoDataRow');

            // 1. Filtrar linhas que coincidem com a busca
            const matchingRows = [];
            rows.forEach(row => {
                const rowText = row.textContent.toLowerCase();
                const matches = (qLower === '' || rowText.indexOf(qLower) !== -1);
                if (matches) {
                    matchingRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });

            const totalMatching = matchingRows.length;
            const totalPages = Math.max(1, Math.ceil(totalMatching / historyPageSize));

            if (historyCurrentPage > totalPages) historyCurrentPage = totalPages;
            if (historyCurrentPage < 1) historyCurrentPage = 1;

            const startIndex = (historyCurrentPage - 1) * historyPageSize;
            const endIndex = Math.min(startIndex + historyPageSize, totalMatching);

            // 2. Exibir apenas a página atual das linhas filtradas
            matchingRows.forEach((row, index) => {
                if (index >= startIndex && index < endIndex) {
                    row.style.display = '';
                    for (let i = 0; i < row.cells.length - 1; i++) {
                        highlightTextInElement(row.cells[i], rawQuery);
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            // 3. Atualizar texto informativo
            const infoEl = document.getElementById('historyPaginationInfo');
            if (infoEl) {
                if (totalMatching === 0) {
                    infoEl.textContent = 'Nenhuma contagem localizada';
                } else {
                    infoEl.textContent = `Mostrando ${startIndex + 1} a ${endIndex} de ${totalMatching} contagens`;
                }
            }

            // 4. Exibir/Ocultar linha de mensagem de resultado vazio
            if (noDataRow) {
                if (totalMatching === 0) {
                    noDataRow.style.display = '';
                } else {
                    noDataRow.style.display = 'none';
                }
            }

            // 5. Renderizar botões de paginação
            const buttonsContainer = document.getElementById('historyPaginationButtons');
            if (!buttonsContainer) return;

            if (totalMatching <= historyPageSize && totalPages <= 1) {
                buttonsContainer.innerHTML = '';
                return;
            }

            let buttonsHtml = '';

            // Botão Anterior
            const prevDisabled = (historyCurrentPage <= 1) ? 'disabled' : '';
            buttonsHtml += `<button type="button" class="pg-btn pg-nav" ${prevDisabled} onclick="goToHistoryPage(${historyCurrentPage - 1})" title="Página Anterior">&lsaquo; Anterior</button>`;

            // Lógica de numeração de páginas
            const maxVisiblePages = 5;
            let startPage = Math.max(1, historyCurrentPage - 2);
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }

            if (startPage > 1) {
                buttonsHtml += `<button type="button" class="pg-btn" onclick="goToHistoryPage(1)">1</button>`;
                if (startPage > 2) {
                    buttonsHtml += `<span class="pg-ellipsis">&hellip;</span>`;
                }
            }

            for (let p = startPage; p <= endPage; p++) {
                const activeClass = (p === historyCurrentPage) ? 'active' : '';
                buttonsHtml += `<button type="button" class="pg-btn ${activeClass}" onclick="goToHistoryPage(${p})">${p}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    buttonsHtml += `<span class="pg-ellipsis">&hellip;</span>`;
                }
                buttonsHtml += `<button type="button" class="pg-btn" onclick="goToHistoryPage(${totalPages})">${totalPages}</button>`;
            }

            // Botão Próxima
            const nextDisabled = (historyCurrentPage >= totalPages) ? 'disabled' : '';
            buttonsHtml += `<button type="button" class="pg-btn pg-nav" ${nextDisabled} onclick="goToHistoryPage(${historyCurrentPage + 1})" title="Próxima Página">Próxima &rsaquo;</button>`;

            buttonsContainer.innerHTML = buttonsHtml;
        }

        function highlightTextInElement(element, query) {
            if (!element.dataset.origHtml) {
                element.dataset.origHtml = element.innerHTML;
            }
            const orig = element.dataset.origHtml;
            if (!query) {
                element.innerHTML = orig;
                return;
            }

            const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp(`(${escapedQuery})`, 'gi');

            const parts = orig.split(/(<[^>]+>)/g);
            const highlightedParts = parts.map(part => {
                if (part.startsWith('<') && part.endsWith('>')) {
                    return part;
                }
                return part.replace(regex, '<mark class="search-highlight">$1</mark>');
            });

            element.innerHTML = highlightedParts.join('');
        }

        // Helper para cálculo do rótulo percentual
        function getPercentualLabel(total) {
            const t = parseInt(total) || 0;
            if (t >= 100) return '120%';
            if (t >= 82) return '100%';
            if (t >= 54) return '65%';
            if (t >= 42) return '50%';
            if (t >= 30) return '35%';
            if (t >= 13) return '15%';
            return '0%';
        }

        // 4.1. ALTERAR LINHA E VEÍCULO DA SESSÃO ATIVA
        function openAlterarLinhaVeiculoModal() {
            const modal = document.getElementById('alterarLinhaVeiculoModal');
            if (modal) {
                modal.classList.add('active');
                const editL = document.getElementById('editIdLinha');
                const editV = document.getElementById('editIdVeiculo');
                if (editL) editL.dispatchEvent(new Event('change'));
                if (editV) editV.dispatchEvent(new Event('change'));
            }
        }

        function closeAlterarLinhaVeiculoModal() {
            const modal = document.getElementById('alterarLinhaVeiculoModal');
            if (modal) modal.classList.remove('active');
        }

        function submitAlterarLinhaVeiculo(e) {
            e.preventDefault();
            const idLinha = document.getElementById('editIdLinha').value;
            const idVeiculo = document.getElementById('editIdVeiculo').value;
            const editTurno = document.getElementById('editTurno');
            const turnoVal = editTurno ? editTurno.value : '';

            if (!idLinha || !idVeiculo) {
                alert('Por favor, selecione a linha e o veículo.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'alterar_linha_veiculo');
            formData.append('idContagem', activeContagemId);
            formData.append('idLinha', idLinha);
            formData.append('idVeiculo', idVeiculo);
            if (turnoVal) formData.append('turno', turnoVal);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const linhaEl = document.getElementById('session-linha-text');
                    const veiculoEl = document.getElementById('session-veiculo-text');
                    const turnoEl = document.getElementById('session-turno-text');
                    if (linhaEl) linhaEl.textContent = data.linhaText;
                    if (veiculoEl) veiculoEl.textContent = data.veiculoText;
                    if (turnoEl && data.turnoText) turnoEl.textContent = data.turnoText;
                    closeAlterarLinhaVeiculoModal();
                } else {
                    alert(data.message || 'Erro ao alterar dados da sessão.');
                }
            })
            .catch(err => console.error('Erro ao alterar dados da sessão:', err));
        }

        // 4. INCREMENTO / SUBSTITUIÇÃO DE CONTAGEM VIA AJAX
        function sendIncrement(qtd, modo, btnEl) {
            if (!activeContagemId) return;

            const counterEl = document.getElementById('counterTotal');
            const percentEl = document.getElementById('counterTotalPercent');
            const numEl = document.getElementById('counterTotalNum');

            if (counterEl) {
                counterEl.classList.add('bounce');
                setTimeout(() => counterEl.classList.remove('bounce'), 200);
            }
            if (percentEl) {
                percentEl.classList.add('bounce');
                setTimeout(() => percentEl.classList.remove('bounce'), 200);
            }

            // Destaque visual imediato no botão percentual clicado
            if (btnEl && btnEl.classList.contains('btn-count-percent')) {
                document.querySelectorAll('.btn-count-percent').forEach(b => {
                    b.classList.remove('active-percent');
                    b.style.boxShadow = 'none';
                    b.style.transform = 'none';
                });
                btnEl.classList.add('active-percent');
                btnEl.style.boxShadow = '0 6px 16px rgba(0,0,0,0.15)';
                btnEl.style.transform = 'translateY(-2px) scale(1.03)';
            }

            const formData = new FormData();
            formData.append('action', 'incrementar');
            formData.append('idContagem', activeContagemId);
            formData.append('qtd', qtd);
            if (modo) {
                formData.append('modo', modo);
            } else if (activeTipoContagem === 'percentual') {
                formData.append('modo', 'substituir');
            }
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (counterEl) counterEl.textContent = data.total;
                    if (percentEl) percentEl.textContent = getPercentualLabel(data.total);
                    if (numEl) numEl.textContent = data.total;
                    if (data.logs) renderLogs(data.logs);
                }
            })
            .catch(err => console.error('Erro na contagem:', err));
        }

        // 5. MODAL DE SOLICITAÇÃO DE CORREÇÃO AO SUPERVISOR
        const percentToPasMap = {
            '15': 13,
            '35': 30,
            '50': 42,
            '65': 54,
            '100': 82,
            '120': 100
        };

        function selectCorrPercent(pct) {
            pct = String(pct);
            const select = document.getElementById('corrPercentSelect');
            if (select) select.value = pct;

            const badge = document.getElementById('corrPercentBadge');
            if (badge) badge.textContent = pct + '%';

            const buttons = document.querySelectorAll('.btn-corr-pct');
            buttons.forEach(btn => {
                if (btn.getAttribute('data-pct') === pct) {
                    btn.style.border = '2px solid #a855f7';
                    btn.style.background = '#f3e8ff';
                    btn.style.color = '#6b21a8';
                } else {
                    btn.style.border = '2px solid #cbd5e1';
                    btn.style.background = '#f8fafc';
                    btn.style.color = '#334155';
                }
            });
        }

        function openCorrectionModal() {
            const modal = document.getElementById('correctionModal');
            if (!modal) return;

            modal.classList.add('active');

            const containerQtd = document.getElementById('containerCorrQtd');
            const containerPercent = document.getElementById('containerCorrPercent');
            const inputQtd = document.getElementById('corrQtd');
            const selectPercent = document.getElementById('corrPercentSelect');

            if (activeTipoContagem === 'percentual') {
                if (containerQtd) containerQtd.style.display = 'none';
                if (containerPercent) containerPercent.style.display = 'block';
                if (inputQtd) inputQtd.required = false;
                if (selectPercent) {
                    selectPercent.required = true;
                    selectCorrPercent(selectPercent.value || '15');
                }
            } else {
                if (containerQtd) containerQtd.style.display = 'block';
                if (containerPercent) containerPercent.style.display = 'none';
                if (inputQtd) {
                    inputQtd.required = true;
                    inputQtd.focus();
                }
                if (selectPercent) selectPercent.required = false;
            }

            const destino = document.getElementById('corrDestino');
            if (destino && activeTipoContagem === 'percentual') destino.focus();
        }

        function closeCorrectionModal() {
            const modal = document.getElementById('correctionModal');
            if (modal) modal.classList.remove('active');
            const form = document.getElementById('correctionForm');
            if (form) form.reset();
            if (activeTipoContagem === 'percentual') {
                selectCorrPercent('15');
            }
        }

        function submitCorrection(e) {
            e.preventDefault();
            if (!activeContagemId) {
                alert('Nenhuma sessão de contagem ativa identificada.');
                return;
            }

            const destinoSelect = document.getElementById('corrDestino');
            const idUsuarioDestino = destinoSelect ? destinoSelect.value : '';
            const justificativa = document.getElementById('corrJustificativa') ? document.getElementById('corrJustificativa').value.trim() : '';

            let qtd = 1;
            let percentualSubtrair = '';
            let justifFinal = justificativa;

            if (activeTipoContagem === 'percentual') {
                const selectPercent = document.getElementById('corrPercentSelect');
                percentualSubtrair = selectPercent ? selectPercent.value : '15';
                qtd = percentToPasMap[percentualSubtrair] || 13;
                justifFinal = `[Estorno de ${percentualSubtrair}%] ` + justificativa;
            } else {
                qtd = document.getElementById('corrQtd') ? document.getElementById('corrQtd').value : 1;
            }

            if (!idUsuarioDestino) {
                alert('Por favor, selecione para qual supervisor/gerente enviar a solicitação de correção.');
                if (destinoSelect) destinoSelect.focus();
                return;
            }

            if (!justificativa || justificativa.length < 3) {
                alert('Por favor, digite uma justificativa explicativa para a correção (mínimo de 3 caracteres).');
                const justEl = document.getElementById('corrJustificativa');
                if (justEl) justEl.focus();
                return;
            }

            const btn = document.getElementById('btnSubmitCorrecao');
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.7';
            }

            const formData = new FormData();
            formData.append('action', 'solicitar_correcao');
            formData.append('idContagem', activeContagemId);
            formData.append('idUsuarioDestino', idUsuarioDestino);
            formData.append('qtd_subtrair', qtd);
            formData.append('percentual_subtrair', percentualSubtrair);
            formData.append('justificativa', justifFinal);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
                if (data.success) {
                    closeCorrectionModal();
                    showToastNotification('✓ ' + (data.message || 'Solicitação enviada ao supervisor com sucesso!'));
                    setTimeout(() => {
                        window.location.href = 'index.php?tab=solicitacoes';
                    }, 2000);
                } else {
                    alert(data.message || 'Erro ao enviar solicitação de correção.');
                }
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
                console.error('Erro na solicitação de correção:', err);
                alert('Ocorreu um erro de comunicação com o servidor ao enviar a solicitação.');
            });
        }

        // 5.1. GERENCIAMENTO DE SOLICITAÇÕES (APROVAR / REJEITAR)
        function aprovarSolicitacao(idSolicitacao, qtd, fiscalNome, idContagem, tipoContagem) {
            const ajusteTexto = (tipoContagem && tipoContagem.toLowerCase().includes('perc'))
                ? `-${getPercentualLabel(qtd)} (${qtd} pas.)`
                : `-${qtd} passageiro(s)`;

            if (!confirm(`Deseja realmente APROVAR a solicitação #${idSolicitacao}?\n\n• Solicitante: ${fiscalNome}\n• Sessão: #${idContagem}\n• Ajuste: ${ajusteTexto}\n\nO valor será descontado da contagem correspondente imediatamente.`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'responder_solicitacao');
            formData.append('idSolicitacao', idSolicitacao);
            formData.append('decisao', 'aprovar');
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToastNotification('✓ ' + (data.message || 'Solicitação aprovada com sucesso!'));
                    setTimeout(() => {
                        window.location.href = 'index.php?tab=solicitacoes';
                    }, 2000);
                } else {
                    alert(data.message || 'Erro ao aprovar solicitação.');
                }
            })
            .catch(err => {
                console.error('Erro ao aprovar solicitação:', err);
                alert('Erro ao processar a aprovação.');
            });
        }

        function abrirModalRejeitar(idSolicitacao, fiscalNome) {
            const idInput = document.getElementById('rejeitarIdSolicitacao');
            const idSpan = document.getElementById('rejeitarModalId');
            const motivoArea = document.getElementById('rejeitarMotivo');
            const modal = document.getElementById('rejeitarSolicitacaoModal');

            if (idInput) idInput.value = idSolicitacao;
            if (idSpan) idSpan.textContent = '#' + idSolicitacao + (fiscalNome ? ' (' + fiscalNome + ')' : '');
            if (motivoArea) motivoArea.value = '';
            if (modal) {
                modal.classList.add('active');
                if (motivoArea) motivoArea.focus();
            }
        }

        function fecharModalRejeitar() {
            const modal = document.getElementById('rejeitarSolicitacaoModal');
            if (modal) modal.classList.remove('active');
        }

        function confirmarRejeicao(e) {
            e.preventDefault();
            const idSolicitacao = document.getElementById('rejeitarIdSolicitacao').value;
            const motivo = document.getElementById('rejeitarMotivo').value.trim();

            if (!motivo) {
                alert('Por favor, informe o motivo da recusa.');
                return;
            }

            const btn = document.getElementById('btnConfirmarRejeicao');
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.7';
            }

            const formData = new FormData();
            formData.append('action', 'responder_solicitacao');
            formData.append('idSolicitacao', idSolicitacao);
            formData.append('decisao', 'rejeitar');
            formData.append('motivo', motivo);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
                if (data.success) {
                    fecharModalRejeitar();
                    showToastNotification(data.message || 'Solicitação rejeitada com sucesso.');
                    setTimeout(() => {
                        window.location.href = 'index.php?tab=solicitacoes';
                    }, 2000);
                } else {
                    alert(data.message || 'Erro ao rejeitar solicitação.');
                }
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }
                console.error('Erro ao rejeitar solicitação:', err);
                alert('Erro ao processar a rejeição.');
            });
        }

        // 5.2. FILTROS E BUSCA NA TABELA DE SOLICITAÇÕES
        function filtrarSolicitacoesPorStatus(status, btn) {
            const pills = document.querySelectorAll('.solic-filter-pill');
            pills.forEach(p => {
                p.classList.remove('active');
                p.style.background = '#f8fafc';
                p.style.color = '#334155';
            });
            if (btn) {
                btn.classList.add('active');
                if (status === 'todos') {
                    btn.style.background = '#0f172a';
                    btn.style.color = '#ffffff';
                } else if (status === 'pendente') {
                    btn.style.background = '#fff7ed';
                    btn.style.color = '#c2410c';
                } else if (status === 'aprovada') {
                    btn.style.background = '#f0fdf4';
                    btn.style.color = '#15803d';
                } else if (status === 'rejeitada') {
                    btn.style.background = '#fff1f2';
                    btn.style.color = '#be123c';
                }
            }

            const searchInput = document.getElementById('buscarSolicitacoesInput');
            const q = searchInput ? searchInput.value.toLowerCase().trim() : '';

            const rows = document.querySelectorAll('#tabelaSolicitacoesBody tr[data-status]');
            let countVisible = 0;

            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const rowText = row.textContent.toLowerCase();

                const matchStatus = (status === 'todos' || rowStatus === status);
                const matchQuery = (q === '' || rowText.indexOf(q) !== -1);

                if (matchStatus && matchQuery) {
                    row.style.display = '';
                    countVisible++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noData = document.getElementById('solicitacoesNoDataRow');
            if (noData) {
                noData.style.display = (countVisible === 0) ? '' : 'none';
            }
        }

        function buscarSolicitacoesTable(query) {
            const activePill = document.querySelector('.solic-filter-pill.active');
            const status = activePill ? activePill.getAttribute('data-status-filter') : 'todos';
            filtrarSolicitacoesPorStatus(status, activePill);
        }

        // 6. MODAL DE DETALHES/EXTRATO DO HISTÓRICO
        function openHistoryDetailsModal(idContagem) {
            document.getElementById('histDetailId').textContent = '#' + idContagem;
            document.getElementById('histModalContent').innerHTML = '<div style="text-align: center; padding: 30px; color: #64748b;">Carregando detalhes do extrato...</div>';
            document.getElementById('historyDetailsModal').classList.add('active');

            const formData = new FormData();
            formData.append('action', 'obter_detalhes_historico');
            formData.append('idContagem', idContagem);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.contagem) {
                    const c = data.contagem;
                    const regs = data.registros || [];

                    let statusBadge = c.status === 'concluida' 
                        ? '<span class="badge-status-concluida">✓ Concluída</span>' 
                        : '<span class="badge-status-andamento">⚡ Em Andamento</span>';

                    let linhaInfo = c.linhaNumero ? `${c.linhaNumero} - ${c.linhaDescricao}` : `Linha #${c.idLinha}`;
                    let veicInfo = c.veiculoPrefixo ? `Prefixo: ${c.veiculoPrefixo}` : `#${c.idVeiculo}`;

                    let html = `
                        <div class="session-info-grid" style="margin-bottom: 20px;">
                            <div class="session-info-item"><label>Linha</label><span>${escapeHtml(linhaInfo)}</span></div>
                            <div class="session-info-item"><label>Veículo</label><span>${escapeHtml(veicInfo)}</span></div>
                            <div class="session-info-item"><label>Local de Parada</label><span>${escapeHtml(c.nomeLocal || 'Local')}</span></div>
                            <div class="session-info-item"><label>Sentido (Viagem)</label><span style="color: #0054A6; font-weight: 700;">${(c.sentido && c.sentido.toLowerCase() === 'volta') ? '⬅️ Volta' : '➡️ Ida'}</span></div>
                            <div class="session-info-item">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <label style="margin: 0;">Turno</label>
                                    <button type="button" onclick="quickEditTurnoHistorico(${c.idContagem}, '${escapeHtml(c.turno || 'Matutino')}')" style="background: none; border: none; color: #000000; cursor: pointer; font-size: 0.76rem; font-weight: 700; padding: 0; text-decoration: underline;" title="Clique para editar o turno desta contagem">
                                        Editar
                                    </button>
                                </div>
                                <span id="hist-turno-val-${c.idContagem}" style="font-weight: 700; color: #000000;">${escapeHtml(c.turno || 'Matutino')}</span>
                            </div>
                            <div class="session-info-item"><label>Início / Fim</label><span>${c.inicioFmt}<br><small style="color:#64748b;">Fim: ${c.fimFmt || 'Em andamento'}</small></span></div>
                        </div>

                        <div style="background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 20px; border-radius: 14px; text-align: center; margin-bottom: 20px;">
                            <div style="font-size: 0.8rem; text-transform: uppercase; color: #94a3b8; font-weight: 600;">Total Final Acumulado</div>
                            <div style="font-size: 3.2rem; font-weight: 800; color: #ffffff;">${c.totalPassageiros}</div>
                            <div>${statusBadge}</div>
                        </div>

                        <h4 style="font-size: 1rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">Histórico de Todos os Lançamentos (${regs.length})</h4>
                        <div style="border: 1px solid #e2e8f0; border-radius: 10px;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.86rem;">
                                <thead>
                                    <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #64748b;">
                                        <th style="padding: 10px;">Data/Hora</th>
                                        <th style="padding: 10px;">Operação</th>
                                        <th style="padding: 10px;">Quantidade</th>
                                        <th style="padding: 10px;">Observação / Justificativa</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    if (regs.length === 0) {
                        html += `<tr><td colspan="4" style="padding: 20px; text-align: center; color: #94a3b8;">Nenhum lançamento registrado nesta sessão.</td></tr>`;
                    } else {
                        regs.forEach(r => {
                            const isInc = (r.tipo === 'incremento');
                            const isSalvo = (r.tipo === 'salvamento');
                            let badgeClass = 'badge-corr';
                            let badgeText = '- Correção';
                            if (isInc) {
                                badgeClass = 'badge-inc';
                                badgeText = '+ Entrada';
                            } else if (isSalvo) {
                                badgeClass = 'badge-salvo';
                                badgeText = '💾 Salvo';
                            }

                            let qtyColor = '#dc2626';
                            let qtyText = r.quantidade;
                            if (isSalvo) {
                                qtyColor = '#2563eb';
                                qtyText = r.quantidade + ' pas.';
                            } else if (r.quantidade > 0) {
                                qtyColor = '#16a34a';
                                qtyText = '+' + r.quantidade;
                            }

                            const obs = r.justificativa ? escapeHtml(r.justificativa) : '<em style="color:#a1a1aa;">Contagem direta</em>';

                            html += `
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px; font-weight: 600; color: #475569;">${r.dataHoraFmt}</td>
                                    <td style="padding: 10px;"><span class="badge-reg ${badgeClass}">${badgeText}</span></td>
                                    <td style="padding: 10px; font-weight: 800; color: ${qtyColor};">${qtyText}</td>
                                    <td style="padding: 10px; color: #334155;">${obs}</td>
                                </tr>
                            `;
                        });
                    }

                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;

                    document.getElementById('histModalContent').innerHTML = html;
                } else {
                    document.getElementById('histModalContent').innerHTML = `<div style="text-align: center; padding: 30px; color: #dc2626;">${data.message || 'Erro ao carregar.'}</div>`;
                }
            })
            .catch(err => {
                document.getElementById('histModalContent').innerHTML = `<div style="text-align: center; padding: 30px; color: #dc2626;">Erro de conexão ao carregar extrato.</div>`;
            });
        }

        function quickEditTurnoHistorico(idContagem, currentTurno) {
            const container = document.getElementById('hist-turno-val-' + idContagem);
            if (!container) return;
            const cur = (currentTurno || 'Matutino').toLowerCase();
            container.innerHTML = `
                <div style="display: inline-flex; align-items: center; gap: 6px; margin-top: 4px;">
                    <select id="sel-hist-turno-${idContagem}" onchange="salvarTurnoHistorico(${idContagem}, this.value)" style="padding: 4px 8px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-weight: 700; font-size: 0.82rem; outline: none; background: #ffffff; color: #000000; cursor: pointer;">
                        <option value="Matutino" ${cur === 'matutino' ? 'selected' : ''}>Matutino</option>
                        <option value="Vespertino" ${cur === 'vespertino' ? 'selected' : ''}>Vespertino</option>
                        <option value="Noturno" ${cur === 'noturno' ? 'selected' : ''}>Noturno</option>
                    </select>
                    <button type="button" onclick="cancelarEditTurnoHistorico(${idContagem}, '${escapeHtml(currentTurno)}')" style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 3px 6px; font-size: 0.72rem; cursor: pointer; color: #000000;" title="Cancelar">x</button>
                </div>
            `;
        }

        function cancelarEditTurnoHistorico(idContagem, originalTurno) {
            const container = document.getElementById('hist-turno-val-' + idContagem);
            if (container) container.textContent = originalTurno;
        }

        function salvarTurnoHistorico(idContagem, novoTurno) {
            const container = document.getElementById('hist-turno-val-' + idContagem);
            if (container) {
                container.innerHTML = `<span style="color: #2563eb; font-size: 0.8rem;">Salvando...</span>`;
            }

            const formData = new FormData();
            formData.append('action', 'alterar_turno_historico');
            formData.append('idContagem', idContagem);
            formData.append('turno', novoTurno);
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (container) {
                        container.innerHTML = `<span style="color: #16a34a; font-weight: 800;">✓ ${escapeHtml(novoTurno)}</span>`;
                        setTimeout(() => {
                            if (container) container.textContent = novoTurno;
                        }, 1200);
                    }
                } else {
                    alert(data.message || 'Erro ao alterar turno.');
                    if (container) container.textContent = novoTurno;
                }
            })
            .catch(err => {
                alert('Erro de comunicação ao salvar o turno.');
                if (container) container.textContent = novoTurno;
            });
        }

        function closeHistoryDetailsModal() {
            document.getElementById('historyDetailsModal').classList.remove('active');
        }

        // 7. RENDERIZAÇÃO DINÂMICA DA TABELA DE HISTÓRICO DA SESSÃO ATIVA
        function openExpandLogsModal() {
            document.getElementById('expandLogsModal').classList.add('active');
            const searchInput = document.getElementById('expandLogSearch');
            if (searchInput) {
                searchInput.value = '';
                filterExpandLogsTable('');
                searchInput.focus();
            }
        }

        function closeExpandLogsModal() {
            document.getElementById('expandLogsModal').classList.remove('active');
        }

        function filterExpandLogsTable(query) {
            const rawQuery = query ? query.trim().toLowerCase() : '';
            const rows = document.querySelectorAll('#expandLogsTableBody tr');

            rows.forEach(row => {
                if (row.cells.length <= 1) return;
                const text = row.textContent.toLowerCase();
                row.style.display = (rawQuery === '' || text.indexOf(rawQuery) !== -1) ? '' : 'none';
            });
        }

        function renderLogs(logs) {
            const tbody = document.getElementById('logsTableBody');
            const expandTbody = document.getElementById('expandLogsTableBody');

            if (!logs || logs.length === 0) {
                const emptyRow = `<tr><td colspan="4" style="padding: 24px; text-align: center; color: #94a3b8;">Nenhum registro efetuado ainda nesta sessão.</td></tr>`;
                if (tbody) tbody.innerHTML = emptyRow;
                if (expandTbody) expandTbody.innerHTML = emptyRow;
                return;
            }

            let htmlNormal = '';
            let htmlExpand = '';

            logs.forEach(reg => {
                const isInc = (reg.tipo === 'incremento');
                const isSalvo = (reg.tipo === 'salvamento');

                let badgeClass = 'badge-corr';
                let badgeText = '- Correção';
                if (isInc) {
                    badgeClass = 'badge-inc';
                    badgeText = '+ Entrada';
                } else if (isSalvo) {
                    badgeClass = 'badge-salvo';
                    badgeText = '💾 Salvo';
                }

                let qtyColor = '#dc2626';
                let qtyText = reg.quantidade;
                if (isSalvo) {
                    qtyColor = '#2563eb';
                    qtyText = (activeTipoContagem === 'percentual' || (typeof activeTipoContagem === 'string' && activeTipoContagem.includes('perc')))
                        ? (getPercentualLabel(reg.quantidade) + ' (' + parseInt(reg.quantidade, 10) + ' pas.)')
                        : (reg.quantidade + ' pas.');
                } else if (reg.quantidade > 0) {
                    qtyColor = '#16a34a';
                    qtyText = '+' + reg.quantidade;
                }

                const isDetalheLongo = (isSalvo || (reg.justificativa && reg.justificativa.indexOf('Operação Salva.') === 0));
                const obsLimpa = isDetalheLongo
                    ? '<span style="color: #15803d; font-weight: 600;">✓ Contagem salva</span>'
                    : (reg.justificativa ? escapeHtml(reg.justificativa) : '<em style="color:#a1a1aa;">Contagem direta</em>');

                htmlNormal += `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px; font-weight: 600; color: #475569;">${reg.horaFormatada || ''}</td>
                        <td style="padding: 12px;"><span class="badge-reg ${badgeClass}">${badgeText}</span></td>
                        <td style="padding: 12px; font-weight: 800; font-size: 1.05rem; color: ${qtyColor};">${qtyText}</td>
                        <td style="padding: 12px; color: #334155;">${obsLimpa}</td>
                    </tr>
                `;

                htmlExpand += `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 14px 16px; font-weight: 600; color: #475569; white-space: nowrap;">${reg.horaFormatada || ''}</td>
                        <td style="padding: 14px 16px;"><span class="badge-reg ${badgeClass}" style="font-size: 0.85rem; padding: 6px 12px;">${badgeText}</span></td>
                        <td style="padding: 14px 16px; font-weight: 800; font-size: 1.1rem; color: ${qtyColor};">${qtyText}</td>
                        <td style="padding: 14px 16px; color: #1e293b; line-height: 1.5; font-size: 0.92rem;">${obsLimpa}</td>
                    </tr>
                `;
            });

            if (tbody) tbody.innerHTML = htmlNormal;
            if (expandTbody) expandTbody.innerHTML = htmlExpand;
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Seleção de Chips Interativos do Painel de Contagem
        function selectChip(groupId, value, element, activeClass) {
            // Se tentar selecionar Quantidade mas a operação for Desembarque, impede a alteração
            if (groupId === 'tipoContagem' && value === 'quantidade') {
                var currentTipoOp = document.getElementById('input_tipoOperacao');
                if (currentTipoOp && currentTipoOp.value === 'desembarque') {
                    return;
                }
            }

            var input = document.getElementById('input_' + groupId);
            if (input) {
                input.value = value;
            }

            if (groupId === 'tipoOperacao') {
                var sentidoInput = document.getElementById('input_sentido');
                if (sentidoInput) {
                    if (value === 'ida') sentidoInput.value = 'Ida';
                    else if (value === 'volta') sentidoInput.value = 'Volta';
                }

                // Quando a pessoa clica em Desembarque: trava o Tipo de Contagem imediatamente em Porcentagem
                var chipPercentual = document.getElementById('chip-percentual');
                var chipQuantidade = document.getElementById('chip-quantidade');
                var inputTipoContagem = document.getElementById('input_tipoContagem');

                if (value === 'desembarque') {
                    if (inputTipoContagem) {
                        inputTipoContagem.value = 'percentual';
                    }
                    if (chipPercentual) {
                        chipPercentual.classList.add('active', 'chip-purple');
                    }
                    if (chipQuantidade) {
                        chipQuantidade.classList.remove('active', 'chip-purple');
                        chipQuantidade.disabled = true;
                        chipQuantidade.style.opacity = '0.45';
                        chipQuantidade.style.cursor = 'not-allowed';
                        chipQuantidade.title = 'Para desembarque, a contagem é travada automaticamente em porcentagem';
                    }
                } else {
                    // Libera a seleção de Tipo de Contagem quando for Embarque ou Ocupação
                    if (chipQuantidade) {
                        chipQuantidade.disabled = false;
                        chipQuantidade.style.opacity = '';
                        chipQuantidade.style.cursor = '';
                        chipQuantidade.title = '';
                    }
                }
            }

            var parent = element.parentElement;
            if (parent) {
                var chips = parent.querySelectorAll('.chip-btn');
                chips.forEach(function(c) {
                    c.classList.remove('active', 'chip-blue', 'chip-purple', 'chip-green', 'chip-navy');
                });
            }

            element.classList.add('active');
            if (activeClass) {
                element.classList.add(activeClass);
            }
            if (typeof atualizarBotaoIniciar === 'function') {
                atualizarBotaoIniciar();
            }
        }

        // ==========================================
        // GERENCIAMENTO DA SELEÇÃO DE VEÍCULOS (FRONT-END)
        // ==========================================
        var veiculosSelecionadosState = [];

        function selecionarModoVeiculo(modo) {
            var radioReg = document.getElementById('radio_modo_registrar');
            var radioNao = document.getElementById('radio_modo_nao_informado');
            var cardReg = document.getElementById('card-modo-registrar');
            var cardNao = document.getElementById('card-modo-nao-informado');
            var boxReg = document.getElementById('box-veiculo-registrar');
            var boxNao = document.getElementById('box-veiculo-nao-informado');

            if (modo === 'registrar') {
                if (radioReg) radioReg.checked = true;
                if (radioNao) radioNao.checked = false;
                if (cardReg) {
                    cardReg.style.borderColor = '#2563eb';
                    cardReg.style.background = '#eff6ff';
                }
                if (cardNao) {
                    cardNao.style.borderColor = '#cbd5e1';
                    cardNao.style.background = '#ffffff';
                }
                if (boxReg) boxReg.style.display = 'block';
                if (boxNao) boxNao.style.display = 'none';

                // Abre o modal para o usuário buscar e marcar os veículos
                openModalVeiculos();
            } else {
                if (radioReg) radioReg.checked = false;
                if (radioNao) radioNao.checked = true;
                if (cardReg) {
                    cardReg.style.borderColor = '#cbd5e1';
                    cardReg.style.background = '#ffffff';
                }
                if (cardNao) {
                    cardNao.style.borderColor = '#2563eb';
                    cardNao.style.background = '#eff6ff';
                }
                if (boxReg) boxReg.style.display = 'none';
                if (boxNao) boxNao.style.display = 'block';

                // Limpa seleções ativas
                veiculosSelecionadosState = [];
                renderVeiculosChips();
                atualizarInputsVeiculos();
            }
            if (typeof atualizarBotaoIniciar === 'function') {
                atualizarBotaoIniciar();
            }
        }

        function openModalVeiculos() {
            var modal = document.getElementById('modalSelecaoVeiculos');
            if (modal) {
                modal.classList.add('active');
                modal.style.display = 'flex';
                sincronizarCheckboxesModal();
                var inputBusca = document.getElementById('inputBuscaVeiculoModal');
                if (inputBusca) {
                    inputBusca.value = '';
                    filtrarVeiculosModal('');
                    setTimeout(function() { inputBusca.focus(); }, 120);
                }
            }
        }

        function closeModalVeiculos() {
            var modal = document.getElementById('modalSelecaoVeiculos');
            if (modal) {
                modal.classList.remove('active');
                modal.style.display = 'none';
            }
        }

        function filtrarVeiculosModal(termo) {
            termo = (termo || '').toLowerCase().trim();
            var items = document.querySelectorAll('#lista-veiculos-modal .item-veiculo-modal');
            var visiveis = 0;
            items.forEach(function(item) {
                var search = item.getAttribute('data-search') || '';
                if (!termo || search.indexOf(termo) !== -1) {
                    item.style.display = 'flex';
                    visiveis++;
                } else {
                    item.style.display = 'none';
                }
            });
            var aviso = document.getElementById('aviso-veiculos-nenhum');
            if (aviso) {
                aviso.style.display = (visiveis === 0) ? 'block' : 'none';
            }
        }

        function atualizarItemVeiculoVisual(chk) {
            var item = chk.closest('.item-veiculo-modal');
            if (!item) return;
            var badge = item.querySelector('.badge-veiculo-checked');
            if (chk.checked) {
                item.style.borderColor = '#2563eb';
                item.style.background = '#f0f7ff';
                if (badge) badge.style.display = 'inline-block';
            } else {
                item.style.borderColor = '#e2e8f0';
                item.style.background = '#ffffff';
                if (badge) badge.style.display = 'none';
            }
            atualizarContadorModal();
        }

        function atualizarContadorModal() {
            var checked = document.querySelectorAll('#lista-veiculos-modal .chk-veiculo-modal:checked');
            var txt = document.getElementById('txt-modal-veiculos-selecionados');
            if (txt) {
                txt.textContent = checked.length + ' veículo(s) selecionado(s)';
            }
            atualizarPainelLateralSelecionados();
        }

        function atualizarPainelLateralSelecionados() {
            var checked = document.querySelectorAll('#lista-veiculos-modal .chk-veiculo-modal:checked');
            var container = document.getElementById('lista-veiculos-selecionados-lateral');
            var badgeQtd = document.getElementById('badge-lateral-qtd');
            if (badgeQtd) badgeQtd.textContent = checked.length;
            if (!container) return;

            if (checked.length === 0) {
                container.innerHTML = '<div id="vazio-lateral-hint" style="text-align: center; padding: 40px 15px; color: #94a3b8; font-size: 0.84rem;">' +
                    '<div style="font-size: 2.2rem; margin-bottom: 8px; opacity: 0.6;">📋</div>' +
                    '<strong style="color: #64748b;">Nenhum veículo selecionado</strong>' +
                    '<p style="margin: 6px 0 0; font-size: 0.78rem; color: #94a3b8; line-height: 1.4;">Marque os veículos na lista de pesquisa ao lado para visualizá-los reunidos aqui.</p>' +
                    '</div>';
                return;
            }

            var html = '';
            checked.forEach(function(chk) {
                var id = chk.value;
                var prefixo = chk.getAttribute('data-prefixo') || id;
                var placa = chk.getAttribute('data-placa') || '';

                html += '<div style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: #ffffff; border: 1.5px solid #bfdbfe; border-left: 4px solid #2563eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(37,99,235,0.06); transition: all 0.15s;">';
                html += '  <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">';
                html += '    <span style="font-size: 1.1rem; line-height: 1;">🚌</span>';
                html += '    <div style="min-width: 0;">';
                html += '      <div style="font-weight: 700; font-size: 0.88rem; color: #1e3a8a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Prefixo: ' + escapeHtml(prefixo) + '</div>';
                if (placa) {
                    html += '  <div style="font-size: 0.75rem; color: #64748b;">Placa: ' + escapeHtml(placa) + '</div>';
                }
                html += '    </div>';
                html += '  </div>';
                html += '  <button type="button" onclick="desmarcarVeiculoPeloId(\'' + escapeHtml(id) + '\')" title="Desmarcar este veículo" style="background: none; border: none; color: #94a3b8; font-size: 1.3rem; cursor: pointer; padding: 2px 6px; line-height: 1; border-radius: 4px; transition: color 0.15s;" onmouseover="this.style.color=\'#dc2626\'" onmouseout="this.style.color=\'#94a3b8\'">&times;</button>';
                html += '</div>';
            });

            container.innerHTML = html;
        }

        function desmarcarVeiculoPeloId(id) {
            var chk = document.querySelector('#lista-veiculos-modal .chk-veiculo-modal[value="' + id + '"]');
            if (chk) {
                chk.checked = false;
                atualizarItemVeiculoVisual(chk);
            }
        }

        function desmarcarTodosVeiculosModal() {
            var chks = document.querySelectorAll('#lista-veiculos-modal .chk-veiculo-modal');
            chks.forEach(function(c) {
                c.checked = false;
                atualizarItemVeiculoVisual(c);
            });
        }

        function sincronizarCheckboxesModal() {
            var chks = document.querySelectorAll('#lista-veiculos-modal .chk-veiculo-modal');
            var idsSelecionados = veiculosSelecionadosState.map(function(v) { return String(v.id); });
            chks.forEach(function(c) {
                c.checked = (idsSelecionados.indexOf(String(c.value)) !== -1);
                atualizarItemVeiculoVisual(c);
            });
            atualizarContadorModal();
        }

        function confirmarSelecaoVeiculosModal() {
            var chks = document.querySelectorAll('#lista-veiculos-modal .chk-veiculo-modal:checked');
            var novos = [];
            chks.forEach(function(c) {
                novos.push({
                    id: c.value,
                    prefixo: c.getAttribute('data-prefixo') || c.value,
                    placa: c.getAttribute('data-placa') || ''
                });
            });
            veiculosSelecionadosState = novos;

            // Se estiver em uma sessão de contagem ativa, salva imediatamente no banco via AJAX
            if (typeof activeContagemId !== 'undefined' && activeContagemId > 0) {
                const ids = novos.map(function(v) { return v.id; }).join(',');
                const formData = new FormData();
                formData.append('action', 'atualizar_veiculos_sessao');
                formData.append('idContagem', activeContagemId);
                formData.append('veiculosSelecionados', ids);
                formData.append('ajax', '1');

                fetch('index.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        renderGridOnibusSessao(data.veiculos || []);
                        showToastNotification('✓ ' + data.message);
                    } else {
                        alert(data.message || 'Erro ao atualizar veículos da contagem.');
                    }
                })
                .catch(err => console.error('Erro ao atualizar veículos:', err));
            } else {
                renderVeiculosChips();
                atualizarInputsVeiculos();
                if (typeof atualizarBotaoIniciar === 'function') {
                    atualizarBotaoIniciar();
                }
            }

            closeModalVeiculos();
        }

        // Abre o modal para gerenciar os veículos selecionados da sessão ativa
        function openModalGerenciarVeiculosSessao() {
            const btns = document.querySelectorAll('#grid-onibus-registrados .btn-opcao-onibus');
            veiculosSelecionadosState = [];
            btns.forEach(btn => {
                const idV = btn.getAttribute('data-id-veiculo');
                if (idV) {
                    veiculosSelecionadosState.push({
                        id: idV,
                        prefixo: btn.getAttribute('data-prefixo') || '',
                        placa: btn.getAttribute('data-placa') || ''
                    });
                }
            });

            openModalVeiculos();
        }

        // Renderiza a grade de ônibus selecionados em tempo real na tela do contador
        function renderGridOnibusSessao(veiculos) {
            const grid = document.getElementById('grid-onibus-registrados');
            const badgeTotal = document.getElementById('badge-total-onibus-cadastrados');
            if (badgeTotal) {
                badgeTotal.textContent = veiculos.length + ' selecionado(s)';
            }
            if (!grid) return;

            if (veiculos.length === 0) {
                grid.innerHTML = '<div style="grid-column: 1 / -1; padding: 18px; text-align: center; color: #64748b; font-size: 0.88rem; background: #ffffff; border-radius: 10px; border: 1px dashed #cbd5e1;">Nenhum ônibus selecionado para esta sessão de contagem.</div>';
                return;
            }

            const editVeic = document.getElementById('editIdVeiculo');
            const idAtivoAtual = editVeic ? parseInt(editVeic.value) : 0;
            let temAtivo = false;

            let html = '';
            veiculos.forEach(v => {
                const isAtivo = (idAtivoAtual && parseInt(v.idVeiculo) === idAtivoAtual);
                if (isAtivo) temAtivo = true;
                const pref = escapeHtml(v.prefixo);
                const plc = v.placa ? escapeHtml(v.placa) : '';
                const searchData = (v.prefixo + ' ' + (v.placa || '')).toLowerCase();

                html += '<button type="button" ' +
                    'class="btn-opcao-onibus ' + (isAtivo ? 'onibus-ativo' : '') + '" ' +
                    'id="btn-veiculo-opt-' + v.idVeiculo + '" ' +
                    'data-id-veiculo="' + v.idVeiculo + '" ' +
                    'data-prefixo="' + pref + '" ' +
                    'data-placa="' + plc + '" ' +
                    'data-search="' + searchData + '" ' +
                    'onclick="selecionarOnibusContagem(' + v.idVeiculo + ', \'' + pref.replace(/'/g, "\\'") + '\')" ' +
                    'style="display: flex; flex-direction: column; align-items: flex-start; padding: 10px 12px; border-radius: 10px; border: 2px solid ' + (isAtivo ? '#2563eb' : '#cbd5e1') + '; background: ' + (isAtivo ? '#eff6ff' : '#ffffff') + '; cursor: pointer; text-align: left; transition: all 0.2s; position: relative;">' +
                    '<div style="display: flex; align-items: center; justify-content: space-between; width: 100%; margin-bottom: 3px;">' +
                        '<span style="font-weight: 800; font-size: 0.95rem; color: ' + (isAtivo ? '#1d4ed8' : '#0f172a') + ';">' +
                            '🚌 ' + pref +
                        '</span>' +
                        '<span class="badge-onibus-status" style="font-size: 0.68rem; font-weight: 800; color: #16a34a; background: #dcfce7; padding: 2px 6px; border-radius: 4px; display: ' + (isAtivo ? 'inline-block' : 'none') + ';">' +
                            '✓ ATIVO' +
                        '</span>' +
                    '</div>' +
                    (plc ? '<div style="font-size: 0.75rem; color: #64748b; font-weight: 600;">Placa: ' + plc + '</div>' : '') +
                '</button>';
            });
            grid.innerHTML = html;

            if (!temAtivo && veiculos.length > 0) {
                selecionarOnibusContagem(veiculos[0].idVeiculo, veiculos[0].prefixo);
            }
        }

        // Permite cadastrar um novo prefixo na frota pelo próprio modal e já marcá-lo como selecionado
        function cadastrarNovoVeiculoModalInline() {
            const inputBusca = document.getElementById('inputBuscaVeiculoModal');
            let sugestao = inputBusca ? inputBusca.value.trim() : '';
            const prefixo = prompt('Digite o Prefixo do novo ônibus a registrar na frota:', sugestao);
            if (!prefixo || !prefixo.trim()) return;

            const placa = prompt('Digite a Placa do veículo (opcional):', '') || '';

            const formData = new FormData();
            formData.append('action', 'cadastrar_veiculo_frota_rapido');
            formData.append('prefixo', prefixo.trim());
            formData.append('placa', placa.trim());
            formData.append('ajax', '1');

            fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Verifica se já existia na lista
                    let chkExistente = document.querySelector('#lista-veiculos-modal .chk-veiculo-modal[value="' + data.idVeiculo + '"]');
                    if (!chkExistente) {
                        const lista = document.getElementById('lista-veiculos-modal');
                        if (lista) {
                            const lbl = document.createElement('label');
                            lbl.className = 'item-veiculo-modal';
                            lbl.setAttribute('data-id', data.idVeiculo);
                            lbl.setAttribute('data-prefixo', data.prefixo);
                            lbl.setAttribute('data-placa', data.placa || '');
                            lbl.setAttribute('data-search', (data.prefixo + ' ' + (data.placa || '')).toLowerCase());
                            lbl.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border: 1.5px solid #2563eb; border-radius: 10px; cursor: pointer; transition: all 0.15s; background: #eff6ff; user-select: none;';

                            lbl.innerHTML = '<div style="display: flex; align-items: center; gap: 12px;">' +
                                '<input type="checkbox" class="chk-veiculo-modal" value="' + data.idVeiculo + '" data-prefixo="' + escapeHtml(data.prefixo) + '" data-placa="' + escapeHtml(data.placa || '') + '" checked onchange="atualizarItemVeiculoVisual(this)" style="width: 19px; height: 19px; accent-color: #2563eb; cursor: pointer;">' +
                                '<div>' +
                                    '<div style="font-weight: 700; font-size: 0.92rem; color: #1e293b;">Prefixo: ' + escapeHtml(data.prefixo) + '</div>' +
                                    (data.placa ? '<div style="font-size: 0.78rem; color: #64748b;">Placa: ' + escapeHtml(data.placa) + '</div>' : '') +
                                '</div>' +
                            '</div>' +
                            '<span class="badge-veiculo-checked" style="font-size: 0.75rem; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 3px 8px; border-radius: 6px; border: 1px solid #bfdbfe;">✓ Selecionado</span>';

                            lista.prepend(lbl);
                        }
                    } else {
                        chkExistente.checked = true;
                        atualizarItemVeiculoVisual(chkExistente);
                    }

                    atualizarPainelLateralSelecionados();
                    showToastNotification('✓ Ônibus ' + data.prefixo + ' registrado e marcado!');
                } else {
                    alert(data.message || 'Erro ao registrar veículo.');
                }
            })
            .catch(err => console.error('Erro ao registrar novo veículo:', err));
        }

        function removerVeiculoSelecionado(id) {
            veiculosSelecionadosState = veiculosSelecionadosState.filter(function(v) {
                return String(v.id) !== String(id);
            });
            renderVeiculosChips();
            atualizarInputsVeiculos();
            if (typeof atualizarBotaoIniciar === 'function') {
                atualizarBotaoIniciar();
            }
        }

        function renderVeiculosChips() {
            var container = document.getElementById('container-veiculos-chips');
            var badgeTotal = document.getElementById('badge-contador-veiculos');
            if (!container) return;

            if (badgeTotal) {
                badgeTotal.textContent = veiculosSelecionadosState.length + ' selecionado(s)';
            }

            if (veiculosSelecionadosState.length === 0) {
                container.innerHTML = '<span id="txt-sem-veiculo-hint" style="font-size: 0.82rem; color: #94a3b8; font-style: italic;">Nenhum veículo selecionado ainda. Clique no botão acima para escolher os veículos para a contagem.</span>';
                return;
            }

            var html = '';
            veiculosSelecionadosState.forEach(function(v) {
                html += '<span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 9999px; font-size: 0.84rem; font-weight: 700; color: #1d4ed8; box-shadow: 0 1px 2px rgba(37,99,235,0.1);">';
                html += '🚌 Prefixo: ' + escapeHtml(v.prefixo);
                if (v.placa) html += ' <span style="font-weight: 500; font-size: 0.76rem; color: #64748b;">(' + escapeHtml(v.placa) + ')</span>';
                html += '<button type="button" onclick="removerVeiculoSelecionado(\'' + escapeHtml(v.id) + '\')" title="Remover" style="background: none; border: none; color: #94a3b8; cursor: pointer; padding: 0 0 0 4px; font-size: 1.1rem; line-height: 1; display: inline-flex; align-items: center;">&times;</button>';
                html += '</span>';
            });
            container.innerHTML = html;
        }

        function atualizarInputsVeiculos() {
            var inputId = document.getElementById('select_idVeiculo');
            var inputMulti = document.getElementById('input_veiculosSelecionados');
            if (veiculosSelecionadosState.length > 0) {
                if (inputId) inputId.value = veiculosSelecionadosState[0].id;
                if (inputMulti) {
                    var ids = veiculosSelecionadosState.map(function(v) { return v.id; });
                    inputMulti.value = ids.join(',');
                }
            } else {
                if (inputId) inputId.value = '';
                if (inputMulti) inputMulti.value = '';
            }
        }

        // ==========================================
        // VALIDAÇÃO TOTAL PARA INICIAR CONTAGEM
        // ==========================================
        function checarStatusFormulario() {
            var formInit = document.getElementById('form-iniciar-contagem');
            if (!formInit) return { valido: true };

            var selLinha = document.getElementById('select_idLinha');
            var selLocal = document.getElementById('select_idLocal');
            var idLinha = selLinha ? selLinha.value : '';
            var idLocal = selLocal ? selLocal.value : '';

            var inputSentido = document.getElementById('input_sentido');
            var inputTipoOp = document.getElementById('input_tipoOperacao');
            var inputTipoCont = document.getElementById('input_tipoContagem');
            var inputCondTempo = document.getElementById('input_condicaoTempo');
            var inputTipoTransp = document.getElementById('input_tipoTransporte');

            var sentido = inputSentido ? inputSentido.value : '';
            var tipoOp = inputTipoOp ? inputTipoOp.value : '';
            var tipoCont = inputTipoCont ? inputTipoCont.value : '';
            var condTempo = inputCondTempo ? inputCondTempo.value : '';
            var tipoTransp = inputTipoTransp ? inputTipoTransp.value : '';

            var radioNaoInf = document.getElementById('radio_modo_nao_informado');
            var radioReg = document.getElementById('radio_modo_registrar');
            var isNaoInformado = radioNaoInf && radioNaoInf.checked;
            var isRegistrar = radioReg && radioReg.checked;
            var temVeiculoRegistrado = (typeof veiculosSelecionadosState !== 'undefined' && veiculosSelecionadosState.length > 0);
            var veiculoValido = isNaoInformado || (isRegistrar && temVeiculoRegistrado);

            var faltaLinha = !idLinha || idLinha.trim() === '';
            var faltaLocal = !idLocal || idLocal.trim() === '';
            var faltaVeiculo = !veiculoValido;
            var faltaViagem = !sentido || sentido.trim() === '';
            var faltaOperacao = !tipoOp || tipoOp.trim() === '';
            var faltaContagem = !tipoCont || tipoCont.trim() === '';
            var faltaTempo = !condTempo || condTempo.trim() === '';
            var faltaTransporte = !tipoTransp || tipoTransp.trim() === '';

            var valido = !faltaLinha && !faltaLocal && !faltaVeiculo &&
                           !faltaViagem && !faltaOperacao && !faltaContagem &&
                           !faltaTempo && !faltaTransporte;

            return {
                valido: valido,
                faltaLinha: faltaLinha,
                faltaLocal: faltaLocal,
                faltaVeiculo: faltaVeiculo,
                faltaViagem: faltaViagem,
                faltaOperacao: faltaOperacao,
                faltaContagem: faltaContagem,
                faltaTempo: faltaTempo,
                faltaTransporte: faltaTransporte,
                isNaoInformado: isNaoInformado
            };
        }

        function atualizarBotaoIniciar() {
            var btn = document.getElementById('btn-iniciar-contagem');
            var statusBox = document.getElementById('status-validacao-contagem');
            if (!btn) return;

            var status = checarStatusFormulario();

            if (!status.valido) {
                btn.disabled = true;
                btn.classList.add('is-disabled');
                btn.setAttribute('title', 'Complete todas as seleções obrigatórias para poder iniciar a contagem');

                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.style.background = '#fff7ed';
                    statusBox.style.borderColor = '#fed7aa';
                    statusBox.style.color = '#c2410c';

                    var pendencias = [];
                    if (status.faltaLinha) pendencias.push('Linha');
                    if (status.faltaLocal) pendencias.push('Local');
                    if (status.faltaVeiculo) pendencias.push('Veículo (escolha ao menos um ou selecione "Não informado")');
                    if (status.faltaViagem) pendencias.push('Viagem');
                    if (status.faltaOperacao) pendencias.push('Tipo de Operação');
                    if (status.faltaContagem) pendencias.push('Tipo de Contagem');
                    if (status.faltaTempo) pendencias.push('Condição do Tempo');
                    if (status.faltaTransporte) pendencias.push('Tipo de Transporte');

                    statusBox.innerHTML = '<span style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #ea580c; flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
                        '<span><strong>Seleções pendentes:</strong> Escolha <u>' + pendencias.join('</u>, <u>') + '</u> para iniciar.</span>' +
                        '</span>';
                }
            } else {
                btn.disabled = false;
                btn.classList.remove('is-disabled');
                btn.removeAttribute('title');

                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.style.background = '#f0fdf4';
                    statusBox.style.borderColor = '#bbf7d0';
                    statusBox.style.color = '#15803d';
                    statusBox.innerHTML = '<span style="display: inline-flex; align-items: center; justify-content: center; gap: 8px;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #16a34a; flex-shrink: 0;"><path d="M20 6 9 17l-5-5"></path></svg>' +
                        '<span><strong>Tudo pronto!</strong> Todas as opções foram selecionadas. Clique em Iniciar Contagem para começar.</span>' +
                        '</span>';
                }
            }
        }

        // Fechar modal ao clicar fora ou com a tecla ESC
        document.addEventListener('DOMContentLoaded', function() {
            var modalV = document.getElementById('modalSelecaoVeiculos');
            if (modalV) {
                modalV.addEventListener('click', function(e) {
                    if (e.target === modalV) {
                        closeModalVeiculos();
                    }
                });
            }
        });


        function filterTableRows(tableId, query) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const q = query.toLowerCase().trim();
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.cells.length <= 1) return;
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        }

        function switchDashTable(targetId, btnElement) {
            const buttons = document.querySelectorAll('.dash-nav-btn');
            buttons.forEach(b => {
                b.style.background = '#f1f5f9';
                b.style.color = '#475569';
                b.style.border = '1px solid #cbd5e1';
                b.style.boxShadow = 'none';
                b.classList.remove('active');
            });

            if (btnElement) {
                btnElement.style.background = '#2563eb';
                btnElement.style.color = '#ffffff';
                btnElement.style.border = 'none';
                btnElement.style.boxShadow = '0 2px 6px rgba(37,99,235,0.25)';
                btnElement.classList.add('active');
            }

            const containers = document.querySelectorAll('.dash-table-card');
            if (targetId === 'dash-all') {
                containers.forEach(c => c.style.display = 'block');
            } else {
                containers.forEach(c => {
                    if (c.id === 'container-' + targetId) {
                        c.style.display = 'block';
                    } else {
                        c.style.display = 'none';
                    }
                });
            }
        }

        function triggerInputPicker(container, evt) {
            const e = evt || window.event;
            if (!container) return;
            const input = container.querySelector('input, select');
            if (!input) return;
            
            // Se o clique veio diretamente de um <select>, o próprio navegador já abre nativamente o menu de opções
            if (e && e.target && e.target.tagName === 'SELECT') {
                return;
            }

            try {
                input.focus();
            } catch (err) {}

            // Para inputs de data, hora ou quando o usuário clica na caixa/label do select:
            if (typeof input.showPicker === 'function') {
                try {
                    input.showPicker();
                    return;
                } catch (err) {}
            }

            try {
                input.click();
            } catch (err) {}
        }

        document.addEventListener('DOMContentLoaded', function() {
            var filterPickers = document.querySelectorAll('.filter-box-clickable input[type="date"], .filter-box-clickable input[type="time"]');
            filterPickers.forEach(function(el) {
                el.addEventListener('click', function(e) {
                    if (typeof this.showPicker === 'function') {
                        try {
                            this.showPicker();
                        } catch (err) {}
                    }
                });
            });
        });

        function toggleExportMenu(menuId, event) {
            if (event) event.stopPropagation();
            const allMenus = document.querySelectorAll('.export-menu-box');
            allMenus.forEach(m => {
                if (m.id !== menuId) m.style.display = 'none';
            });
            const targetMenu = document.getElementById(menuId);
            if (targetMenu) {
                targetMenu.style.display = (targetMenu.style.display === 'block') ? 'none' : 'block';
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.export-dropdown-wrapper')) {
                const allMenus = document.querySelectorAll('.export-menu-box');
                allMenus.forEach(m => m.style.display = 'none');
            }
        });

        function exportTable(tableId, format, filename) {
            const allMenus = document.querySelectorAll('.export-menu-box');
            allMenus.forEach(m => m.style.display = 'none');

            if (format === 'csv') {
                exportToCSV(tableId, filename);
            } else if (format === 'doc') {
                exportToDOC(tableId, filename);
            } else if (format === 'pdf') {
                exportToPDF(tableId, filename);
            }
        }

        function exportToCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                if (row.style.display === 'none') return;
                let rowData = [];
                const cols = row.querySelectorAll('th, td');
                
                cols.forEach(col => {
                    let text = col.innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
                    text = text.replace(/"/g, '""');
                    rowData.push('"' + text + '"');
                });
                
                if (rowData.length > 0) {
                    csv.push(rowData.join(';'));
                }
            });
            
            const csvContent = '\uFEFF' + csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.setAttribute('download', filename + '.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function exportToDOC(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const clone = table.cloneNode(true);
            const hiddenRows = clone.querySelectorAll('tr[style*="display: none"]');
            hiddenRows.forEach(r => r.remove());
            
            clone.style.borderCollapse = 'collapse';
            clone.style.width = '100%';
            const cells = clone.querySelectorAll('th, td');
            cells.forEach(cell => {
                cell.style.border = '1px solid #cbd5e1';
                cell.style.padding = '8px';
                cell.style.fontFamily = 'Arial, sans-serif';
            });

            const headerTitle = filename.replace(/_/g, ' ');

            const htmlContent = `
                <html xmlns:o='urn:schemas-microsoft-microsoft-com:office:office' 
                      xmlns:w='urn:schemas-microsoft-microsoft-com:office:word' 
                      xmlns='http://www.w3.org/TR/REC-html40'>
                <head>
                    <meta charset='utf-8'>
                    <title>${headerTitle}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; color: #0f172a; }
                        h2 { color: #0f172a; text-align: center; font-size: 18pt; margin-bottom: 5px; }
                        p { text-align: center; color: #64748b; font-size: 10pt; margin-bottom: 20px; }
                        table { border-collapse: collapse; width: 100%; margin-top: 15px; }
                        th { background-color: #2563eb; color: #ffffff; font-weight: bold; font-size: 11pt; padding: 10px; border: 1px solid #1d4ed8; }
                        td { border: 1px solid #cbd5e1; padding: 8px 10px; font-size: 10.5pt; }
                    </style>
                </head>
                <body>
                    <h2>Relatório contaBuzu — ${headerTitle}</h2>
                    <p>Gerado em: ${new Date().toLocaleString('pt-BR')}</p>
                    ${clone.outerHTML}
                </body>
                </html>
            `;

            const blob = new Blob(['\uFEFF' + htmlContent], { type: 'application/msword;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.setAttribute('download', filename + '.doc');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function exportToPDF(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const element = document.createElement('div');
            element.style.padding = '20px';
            element.style.fontFamily = 'Arial, sans-serif';

            const titleText = filename.replace(/_/g, ' ');
            
            const title = document.createElement('h2');
            title.style.color = '#0f172a';
            title.style.fontSize = '18px';
            title.style.marginBottom = '4px';
            title.style.textAlign = 'center';
            title.textContent = 'Relatório contaBuzu — ' + titleText;
            
            const subtitle = document.createElement('p');
            subtitle.style.color = '#64748b';
            subtitle.style.fontSize = '12px';
            subtitle.style.marginBottom = '15px';
            subtitle.style.textAlign = 'center';
            subtitle.textContent = 'Gerado em: ' + new Date().toLocaleString('pt-BR');

            const tableClone = table.cloneNode(true);
            const hiddenRows = tableClone.querySelectorAll('tr[style*="display: none"]');
            hiddenRows.forEach(r => r.remove());

            tableClone.style.width = '100%';
            tableClone.style.fontSize = '11px';
            tableClone.style.borderCollapse = 'collapse';
            
            const cells = tableClone.querySelectorAll('th, td');
            cells.forEach(cell => {
                cell.style.border = '1px solid #cbd5e1';
                cell.style.padding = '8px 10px';
            });

            element.appendChild(title);
            element.appendChild(subtitle);
            element.appendChild(tableClone);

            if (typeof html2pdf !== 'undefined') {
                const opt = {
                    margin:       [10, 10, 10, 10],
                    filename:     filename + '.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
                };
                html2pdf().set(opt).from(element).save();
            } else {
                const win = window.open('', '_blank');
                win.document.write(`
                    <html>
                    <head>
                        <title>${titleText}</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 20px; }
                            h2 { text-align: center; color: #0f172a; }
                            p { text-align: center; color: #64748b; font-size: 12px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                            th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-size: 12px; }
                            th { background: #2563eb; color: white; }
                        </style>
                    </head>
                    <body>
                        ${element.innerHTML}
                        <script>window.onload = function() { window.print(); window.close(); };<\/script>
                    </body>
                    </html>
                `);
                win.document.close();
            }
        }

        // =============================================================================
        // MOTOR DE SELEÇÃO COM BUSCA E DIGITAÇÃO INTELIGENTE (CASE & ACCENT INSENSITIVE)
        // =============================================================================
        function cbFoldChar(c) {
            return c.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        }

        function cbFoldString(str) {
            return Array.from(str || '').map(cbFoldChar).join('');
        }

        function cbEscapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function cbHighlightMatches(text, query) {
            if (!query || !query.trim()) return cbEscapeHtml(text);
            const words = query.trim().split(/\s+/).filter(Boolean);
            if (!words.length) return cbEscapeHtml(text);

            const foldedText = cbFoldString(text);
            const textChars = Array.from(text);
            const mask = new Array(textChars.length).fill(false);

            words.forEach(function(w) {
                const foldedWord = cbFoldString(w);
                if (!foldedWord) return;
                let pos = 0;
                while ((pos = foldedText.indexOf(foldedWord, pos)) !== -1) {
                    for (let k = 0; k < foldedWord.length; k++) {
                        if (pos + k < mask.length) mask[pos + k] = true;
                    }
                    pos += 1;
                }
            });

            let result = '';
            let inMark = false;
            for (let i = 0; i < textChars.length; i++) {
                const ch = cbEscapeHtml(textChars[i]);
                if (mask[i] && !inMark) {
                    result += '<mark class="cb-search-highlight">';
                    inMark = true;
                } else if (!mask[i] && inMark) {
                    result += '</mark>';
                    inMark = false;
                }
                result += ch;
            }
            if (inMark) result += '</mark>';
            return result;
        }

        function initSearchableSelect(selectEl) {
            if (!selectEl || selectEl.dataset.searchableInitialized === 'true') return;
            selectEl.dataset.searchableInitialized = 'true';

            const isRequired = selectEl.hasAttribute('required') || selectEl.getAttribute('data-required') === 'true';
            const placeholder = (selectEl.getAttribute('data-placeholder') || 'Digite para pesquisar...').trim();
            const maxLen = selectEl.getAttribute('data-maxlength') ? parseInt(selectEl.getAttribute('data-maxlength'), 10) : null;

            // Ocultar select original para manter o valor no formulário
            selectEl.classList.add('cb-select-hidden');
            if (isRequired) {
                selectEl.removeAttribute('required');
                selectEl.setAttribute('data-required', 'true');
            }

            // Criar container do componente
            const container = document.createElement('div');
            container.className = 'cb-searchable-container';

            container.innerHTML = `
                <div class="cb-searchable-control">
                    <input type="text" class="cb-searchable-input" autocomplete="off" spellcheck="false" placeholder="${cbEscapeHtml(placeholder)}" />
                    <div class="cb-searchable-actions">
                        <button type="button" class="cb-searchable-clear" title="Limpar seleção">&times;</button>
                        <span class="cb-searchable-arrow" title="Abrir opções">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </span>
                    </div>
                </div>
                <div class="cb-searchable-error-msg">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span>Por favor, selecione uma opção válida da lista.</span>
                </div>
                <div class="cb-searchable-dropdown" style="display: none;">
                    <div class="cb-searchable-status">
                        <span>Opções disponíveis</span>
                        <span class="cb-searchable-count">0 opções</span>
                    </div>
                    <div class="cb-searchable-list" role="listbox"></div>
                </div>
            `;

            selectEl.parentNode.insertBefore(container, selectEl);
            container.appendChild(selectEl);

            const control = container.querySelector('.cb-searchable-control');
            const input = container.querySelector('.cb-searchable-input');
            const clearBtn = container.querySelector('.cb-searchable-clear');
            const arrowBtn = container.querySelector('.cb-searchable-arrow');
            const dropdown = container.querySelector('.cb-searchable-dropdown');
            const listEl = container.querySelector('.cb-searchable-list');
            const countEl = container.querySelector('.cb-searchable-count');

            let activeIndex = -1;
            let visibleItems = [];

            function getItems() {
                const items = [];
                Array.from(selectEl.options).forEach(function(opt) {
                    const val = opt.value;
                    const text = opt.textContent.trim();
                    if (val === '' && (text.startsWith('--') || text === '')) return;
                    items.push({
                        value: val,
                        text: text,
                        selected: opt.selected,
                        disabled: opt.disabled
                    });
                });
                return items;
            }

            function renderList(query) {
                const allItems = getItems();
                const words = (query || '').trim().split(/\s+/).filter(Boolean);

                visibleItems = allItems.filter(function(item) {
                    if (words.length === 0) return true;
                    const foldedItemText = cbFoldString(item.text);
                    return words.every(function(w) {
                        return foldedItemText.indexOf(cbFoldString(w)) !== -1;
                    });
                });

                countEl.textContent = `${visibleItems.length} de ${allItems.length}`;
                listEl.innerHTML = '';
                activeIndex = -1;

                if (visibleItems.length === 0) {
                    listEl.innerHTML = `
                        <div class="cb-searchable-empty">
                            <div>Nenhum resultado para "<strong>${cbEscapeHtml(query)}</strong>"</div>
                            <div style="font-size: 0.78rem; opacity: 0.8; margin-top: 3px;">Tente digitar outro nome, número ou limpe o campo.</div>
                        </div>
                    `;
                    return;
                }

                visibleItems.forEach(function(item, idx) {
                    const isSelected = String(item.value) === String(selectEl.value);
                    const itemEl = document.createElement('div');
                    itemEl.className = 'cb-searchable-item' + (isSelected ? ' is-selected' : '');
                    itemEl.setAttribute('role', 'option');
                    itemEl.setAttribute('data-index', idx);
                    itemEl.setAttribute('data-value', item.value);

                    const highlighted = cbHighlightMatches(item.text, query);
                    itemEl.innerHTML = `
                        <span class="cb-searchable-item-text">${highlighted}</span>
                        ${isSelected ? '<span class="cb-searchable-item-check" title="Selecionado">✓</span>' : ''}
                    `;

                    itemEl.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        selectOption(item);
                    });

                    listEl.appendChild(itemEl);
                });
            }

            function openDropdown() {
                document.querySelectorAll('.cb-searchable-container.is-open').forEach(function(other) {
                    if (other !== container) {
                        other.classList.remove('is-open', 'is-focused');
                        const dd = other.querySelector('.cb-searchable-dropdown');
                        if (dd) dd.style.display = 'none';
                    }
                });

                container.classList.add('is-open', 'is-focused');
                dropdown.style.display = 'block';
                renderList(input.value);

                setTimeout(function() {
                    const selectedEl = listEl.querySelector('.cb-searchable-item.is-selected');
                    if (selectedEl) {
                        selectedEl.scrollIntoView({ block: 'nearest' });
                    }
                }, 30);
            }

            function closeDropdown() {
                container.classList.remove('is-open');
                dropdown.style.display = 'none';
                activeIndex = -1;
                syncInputWithSelect();
            }

            function selectOption(item) {
                selectEl.value = item ? item.value : '';
                syncInputWithSelect();
                closeDropdown();
                container.classList.remove('cb-has-error');

                const event = new Event('change', { bubbles: true });
                selectEl.dispatchEvent(event);
            }

            function syncInputWithSelect() {
                const currentVal = selectEl.value;
                const selectedOpt = Array.from(selectEl.options).find(function(o) {
                    return String(o.value) === String(currentVal) && o.value !== '';
                });

                if (selectedOpt) {
                    input.removeAttribute('maxlength');
                    input.value = selectedOpt.textContent.trim();
                    clearBtn.style.display = 'flex';
                } else {
                    if (maxLen) input.setAttribute('maxlength', maxLen);
                    input.value = '';
                    clearBtn.style.display = 'none';
                }
            }

            input.addEventListener('focus', function() {
                container.classList.add('is-focused');
                openDropdown();
                if (maxLen) input.setAttribute('maxlength', maxLen);
                if (input.value) {
                    input.select();
                }
            });

            input.addEventListener('input', function() {
                container.classList.remove('cb-has-error');
                if (maxLen && input.value.length > maxLen) {
                    input.value = input.value.slice(0, maxLen);
                }
                if (!container.classList.contains('is-open')) {
                    openDropdown();
                } else {
                    renderList(input.value);
                }
                if (input.value.trim() !== '') {
                    clearBtn.style.display = 'flex';
                } else {
                    if (!selectEl.value) clearBtn.style.display = 'none';
                }
            });

            input.addEventListener('keydown', function(e) {
                if (!container.classList.contains('is-open')) {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter') {
                        e.preventDefault();
                        openDropdown();
                        return;
                    }
                }

                const items = listEl.querySelectorAll('.cb-searchable-item');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (items.length === 0) return;
                    activeIndex = (activeIndex + 1) % items.length;
                    highlightItem(items, activeIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (items.length === 0) return;
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    highlightItem(items, activeIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIndex >= 0 && activeIndex < visibleItems.length) {
                        selectOption(visibleItems[activeIndex]);
                    } else if (visibleItems.length === 1) {
                        selectOption(visibleItems[0]);
                    } else {
                        closeDropdown();
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeDropdown();
                } else if (e.key === 'Tab') {
                    closeDropdown();
                }
            });

            function highlightItem(items, index) {
                items.forEach(function(el, i) {
                    if (i === index) {
                        el.classList.add('is-active');
                        el.scrollIntoView({ block: 'nearest' });
                    } else {
                        el.classList.remove('is-active');
                    }
                });
            }

            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                selectOption(null);
                if (maxLen) input.setAttribute('maxlength', maxLen);
                openDropdown();
                input.focus();
            });

            arrowBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (container.classList.contains('is-open')) {
                    closeDropdown();
                } else {
                    input.focus();
                    openDropdown();
                }
            });

            control.addEventListener('click', function(e) {
                if (e.target !== clearBtn && e.target !== arrowBtn && e.target !== input) {
                    input.focus();
                    if (!container.classList.contains('is-open')) {
                        openDropdown();
                    }
                }
            });

            selectEl.addEventListener('change', function() {
                syncInputWithSelect();
            });

            syncInputWithSelect();
        }

        // Fechar dropdowns ao clicar fora
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.cb-searchable-container')) {
                document.querySelectorAll('.cb-searchable-container.is-open').forEach(function(cont) {
                    cont.classList.remove('is-open', 'is-focused');
                    const dd = cont.querySelector('.cb-searchable-dropdown');
                    if (dd) dd.style.display = 'none';
                    const sel = cont.querySelector('select');
                    const inp = cont.querySelector('.cb-searchable-input');
                    const clr = cont.querySelector('.cb-searchable-clear');
                    if (sel && inp) {
                        const curVal = sel.value;
                        const opt = Array.from(sel.options).find(function(o) { return String(o.value) === String(curVal) && o.value !== ''; });
                        if (opt) {
                            inp.value = opt.textContent.trim();
                            if (clr) clr.style.display = 'flex';
                        } else {
                            inp.value = '';
                            if (clr) clr.style.display = 'none';
                        }
                    }
                });
            }
        });

        // Limpeza e redefinição de todas as seleções obrigatórias para um estado totalmente limpo
        function limparTodasSelecoesObrigatorias() {
            const formInit = document.getElementById('form-iniciar-contagem');
            if (!formInit) return;

            // 1. Limpar storage legado
            try {
                localStorage.removeItem('cb_last_idLinha');
                localStorage.removeItem('cb_last_idVeiculo');
                localStorage.removeItem('cb_last_idLocal');
                localStorage.removeItem('cb_last_tipoOperacao');
            } catch (e) {}

            // 2. Limpar selects e inputs de linha e local
            const selLinha = document.getElementById('select_idLinha');
            if (selLinha) {
                selLinha.value = '';
                selLinha.selectedIndex = 0;
            }
            const selLocal = document.getElementById('select_idLocal');
            if (selLocal) {
                selLocal.value = '';
                selLocal.selectedIndex = 0;
            }

            // 3. Limpar inputs de sentido, tipoOperacao, tipoContagem, condicaoTempo, tipoTransporte
            if (formInit.elements['sentido']) formInit.elements['sentido'].value = '';
            if (formInit.elements['tipoOperacao']) formInit.elements['tipoOperacao'].value = '';
            if (formInit.elements['tipoContagem']) formInit.elements['tipoContagem'].value = '';
            if (formInit.elements['condicaoTempo']) formInit.elements['condicaoTempo'].value = '';
            if (formInit.elements['tipoTransporte']) formInit.elements['tipoTransporte'].value = '';
            if (formInit.elements['idVeiculo']) formInit.elements['idVeiculo'].value = '';
            if (formInit.elements['veiculosSelecionados']) formInit.elements['veiculosSelecionados'].value = '';

            // 4. Remover classe active de todos os botões/chips
            document.querySelectorAll('#form-iniciar-contagem .chip-btn').forEach(function(btn) {
                btn.classList.remove('active', 'chip-blue', 'chip-purple', 'chip-green', 'chip-navy');
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.cursor = '';
                btn.title = '';
            });

            // 5. Limpar seleção de veículo (nenhum radio marcado, caixas neutras)
            const radioReg = document.getElementById('radio_modo_registrar');
            const radioNao = document.getElementById('radio_modo_nao_informado');
            const cardReg = document.getElementById('card-modo-registrar');
            const cardNao = document.getElementById('card-modo-nao-informado');
            const boxReg = document.getElementById('box-veiculo-registrar');
            const boxNao = document.getElementById('box-veiculo-nao-informado');
            if (radioReg) radioReg.checked = false;
            if (radioNao) radioNao.checked = false;
            if (cardReg) {
                cardReg.style.borderColor = '#cbd5e1';
                cardReg.style.background = '#ffffff';
            }
            if (cardNao) {
                cardNao.style.borderColor = '#cbd5e1';
                cardNao.style.background = '#ffffff';
            }
            if (boxReg) boxReg.style.display = 'none';
            if (boxNao) boxNao.style.display = 'none';

            veiculosSelecionadosState = [];
            const selV = document.getElementById('select_idVeiculo');
            if (selV) selV.value = '';
            const inpV = document.getElementById('input_veiculosSelecionados');
            if (inpV) inpV.value = '';

            const modalChks = document.querySelectorAll('#lista-veiculos-modal .chk-veiculo-modal');
            modalChks.forEach(function(c) {
                c.checked = false;
                if (typeof atualizarItemVeiculoVisual === 'function') {
                    atualizarItemVeiculoVisual(c);
                }
            });

            if (typeof renderVeiculosChips === 'function') renderVeiculosChips();
            if (typeof atualizarContadorModal === 'function') atualizarContadorModal();

            // 6. Limpar o texto dos inputs pesquisáveis customizados
            document.querySelectorAll('#form-iniciar-contagem .cb-searchable-container').forEach(function(cont) {
                const inp = cont.querySelector('.cb-searchable-input');
                const clr = cont.querySelector('.cb-searchable-clear');
                if (inp) inp.value = '';
                if (clr) clr.style.display = 'none';
                cont.classList.remove('cb-has-error');
            });

            // 7. Atualizar validação
            if (typeof atualizarBotaoIniciar === 'function') {
                atualizarBotaoIniciar();
            }
        }

        // Esconder alertas e gerenciar aba ativa na carga da página
        document.addEventListener("DOMContentLoaded", function() {
            // Inicializar todos os selects pesquisáveis na página
            document.querySelectorAll('.cb-searchable-select').forEach(initSearchableSelect);

            const formInit = document.getElementById('form-iniciar-contagem');
            if (formInit) {
                // Garantir todas as seleções obrigatórias sem nada escolhido
                limparTodasSelecoesObrigatorias();

                // Validação no submit (sem salvar seleções para a próxima contagem)
                formInit.addEventListener('submit', function(e) {
                    var status = checarStatusFormulario();
                    if (!status.valido) {
                        e.preventDefault();
                        e.stopPropagation();

                        if (status.faltaLinha) {
                            var selL = document.getElementById('select_idLinha');
                            var wrapL = selL ? selL.closest('.cb-searchable-container') : null;
                            if (wrapL) {
                                wrapL.classList.add('cb-has-error');
                                wrapL.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                var inpL = wrapL.querySelector('.cb-searchable-input');
                                if (inpL) inpL.focus();
                            }
                        } else if (status.faltaLocal) {
                            var selLoc = document.getElementById('select_idLocal');
                            var wrapLoc = selLoc ? selLoc.closest('.cb-searchable-container') : null;
                            if (wrapLoc) {
                                wrapLoc.classList.add('cb-has-error');
                                wrapLoc.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                var inpLoc = wrapLoc.querySelector('.cb-searchable-input');
                                if (inpLoc) inpLoc.focus();
                            }
                        } else if (status.faltaVeiculo) {
                            var boxV = document.getElementById('veiculo-section-group');
                            if (boxV) {
                                boxV.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                boxV.style.outline = '2.5px solid #ef4444';
                                boxV.style.outlineOffset = '4px';
                                boxV.style.borderRadius = '12px';
                                setTimeout(function() {
                                    boxV.style.outline = '';
                                    boxV.style.outlineOffset = '';
                                }, 3000);
                            }
                            openModalVeiculos();
                        }

                        atualizarBotaoIniciar();
                        return false;
                    }
                });

                formInit.addEventListener('change', function() {
                    atualizarBotaoIniciar();
                });
            }

            // Ao retornar via histórico de navegação/cache (Back/Forward), força a limpeza de todas as seleções
            window.addEventListener('pageshow', function() {
                limparTodasSelecoesObrigatorias();
            });

            // Validação inicial do formulário para bloquear/liberar o botão
            if (typeof atualizarBotaoIniciar === 'function') {
                atualizarBotaoIniciar();
            }

            // Inicializar a paginação da tabela de histórico de contagens
            renderHistoryPage();

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('tab') === 'solicitacoes') {
                const solicBtn = document.getElementById('btn-tab-solicitacoes');
                if (solicBtn) {
                    switchUserTab('tab-solicitacoes', solicBtn);
                } else {
                    const contBtn = document.getElementById('btn-tab-contador');
                    if (contBtn) switchUserTab('tab-contador', contBtn);
                }
            } else if (urlParams.has('data_inicio') || urlParams.has('data_fim') || urlParams.has('hora_inicio') || urlParams.has('hora_fim') || urlParams.has('idEmpresa') || urlParams.has('idLinha') || urlParams.has('idVeiculo') || urlParams.has('idLocal') || urlParams.has('tipoOperacao') || urlParams.get('tab') === 'historico') {
                const histBtn = document.getElementById('btn-tab-historico');
                if (histBtn) switchUserTab('tab-historico', histBtn);
            }

            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alertEl) {
                setTimeout(function() {
                    alertEl.classList.add('fade-out');
                    setTimeout(function() {
                        alertEl.style.display = 'none';
                    }, 500);
                }, 2000);
            });
        });
    </script>
</body>
</html>
