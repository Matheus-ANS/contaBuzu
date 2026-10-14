<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
session_start();

// Proteção da página: requer login e privilégio de administrador
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

// Validação estrita do privilégio de administrador e status ativo diretamente no banco de dados
try {
    $stmtAdminCheck = $pdo->prepare("SELECT acesso, nome, login, ativo FROM usuarios WHERE idusuarios = ? LIMIT 1");
    $stmtAdminCheck->execute([$_SESSION['usuario_id']]);
    $userDbData = $stmtAdminCheck->fetch();

    if (!$userDbData || (int)$userDbData['acesso'] !== 1 || (isset($userDbData['ativo']) && (int)$userDbData['ativo'] === 0)) {
        session_unset();
        session_destroy();
        header('Location: login.php?erro=inativo');
        exit;
    }

    // Sincroniza sessão com dados verificados do banco
    $_SESSION['acesso'] = (int)$userDbData['acesso'];
    $_SESSION['nome']   = $userDbData['nome'];
    $_SESSION['login']  = $userDbData['login'];
    $_SESSION['ativo']  = (int)$userDbData['ativo'];
} catch (PDOException $e) {
    // Nega o acesso por segurança em caso de falha de conexão ou erro no banco
    header('Location: index.php');
    exit;
}

$loggedNome   = $_SESSION['nome'];
$loggedLogin  = $_SESSION['login'];
$loggedAcesso = (int)$_SESSION['acesso'];
$isAdmin      = true;

// Gerar iniciais do usuário para o avatar
$nameParts = explode(' ', trim((string)$loggedNome));
$initials = '';
if (count($nameParts) > 0 && $nameParts[0] !== '') {
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
    }
}
if (!$initials) $initials = 'ADM';

$mensagemSucesso = '';
$mensagemErro = '';

$activeTab = 'tab-linhas';

// Helper para identificar a tabela real que possui dados ou existe (singular vs plural)
$getRealTable = function($tableSingular, $tablePlural) use ($pdo) {
    try {
        $hasS = false;
        $hasP = false;
        $stS = $pdo->query("SHOW TABLES LIKE '$tableSingular'");
        if ($stS && count($stS->fetchAll()) > 0) $hasS = true;
        $stP = $pdo->query("SHOW TABLES LIKE '$tablePlural'");
        if ($stP && count($stP->fetchAll()) > 0) $hasP = true;

        if ($hasS && $hasP) {
            $countS = (int)$pdo->query("SELECT COUNT(*) FROM `$tableSingular`")->fetchColumn();
            if ($countS > 0) return $tableSingular;
            $countP = (int)$pdo->query("SELECT COUNT(*) FROM `$tablePlural`")->fetchColumn();
            if ($countP > 0) return $tablePlural;
            return $tableSingular;
        }
        if ($hasS) return $tableSingular;
        if ($hasP) return $tablePlural;
    } catch (\Exception $e) {}
    return $tableSingular;
};

// Helper seguro para obter colunas da tabela linha
$getColsLinha = function() use ($pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM linha");
        if ($stmt) {
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (\PDOException $e) {
    } catch (\Exception $e) {
    }
    return array();
};

$formatNumeroLinha = function($val) {
    $val = trim((string)$val);
    if ($val === '') return '';
    $digits = preg_replace('/\D/', '', $val);
    if (strlen($digits) >= 6) {
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 2);
    } elseif (strlen($digits) > 0) {
        $padded = str_pad($digits, 6, '0', STR_PAD_LEFT);
        return substr($padded, 0, 4) . '-' . substr($padded, 4, 2);
    }
    return $val;
};

$formatDescricaoLinha = function($val) {
    return mb_strtoupper(trim((string)$val), 'UTF-8');
};

// ==========================================
// PROCESSAMENTO DE AÇÕES (CRUD)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!empty($_POST['active_tab'])) {
        $activeTab = trim($_POST['active_tab']);
    } elseif (!empty($action)) {
        if (strpos($action, 'linha') !== false) $activeTab = 'tab-linhas';
        elseif (strpos($action, 'local') !== false) $activeTab = 'tab-locais';
        elseif (strpos($action, 'usuario') !== false) $activeTab = 'tab-usuarios';
        elseif (strpos($action, 'veiculo') !== false) $activeTab = 'tab-veiculos';
        elseif (strpos($action, 'empresa') !== false) $activeTab = 'tab-empresas';
        elseif (strpos($action, 'sistema') !== false) $activeTab = 'tab-sistemas';
    }

    try {
        // --- TABELA: LINHA ---
        if ($action === 'create_linha') {
            $numero    = isset($_POST['numero']) ? $formatNumeroLinha($_POST['numero']) : '';
            $descricao = isset($_POST['descricao']) ? $formatDescricaoLinha($_POST['descricao']) : (isset($_POST['linha']) ? $formatDescricaoLinha($_POST['linha']) : '');
            $ativo     = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($numero !== '' || $descricao !== '') {
                $colsLinha = $getColsLinha();
                if (in_array('numero', $colsLinha) && in_array('descricao', $colsLinha)) {
                    if (in_array('linha', $colsLinha)) {
                        $stmt = $pdo->prepare("INSERT INTO linha (numero, descricao, linha, ativo) VALUES (:numero, :descricao, :linha, :ativo)");
                        $stmt->execute([
                            ':numero'    => $numero,
                            ':descricao' => $descricao,
                            ':linha'     => $descricao !== '' ? $descricao : $numero,
                            ':ativo'     => $ativo
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO linha (numero, descricao, ativo) VALUES (:numero, :descricao, :ativo)");
                        $stmt->execute([
                            ':numero'    => $numero,
                            ':descricao' => $descricao,
                            ':ativo'     => $ativo
                        ]);
                    }
                } else {
                    $valLinha = ($numero !== '' ? $numero . ' - ' : '') . $descricao;
                    $stmt = $pdo->prepare("INSERT INTO linha (linha, ativo) VALUES (:linha, :ativo)");
                    $stmt->execute([':linha' => $valLinha, ':ativo' => $ativo]);
                }
                $mensagemSucesso = "Linha " . ($numero !== '' ? "nº $numero " : "") . ($descricao !== '' ? "'$descricao' " : "") . "cadastrada com sucesso!";
            } else {
                $mensagemErro = "Preencha o número ou a descrição da Linha.";
            }
        } elseif ($action === 'update_linha') {
            $idLinha   = (int)$_POST['idLinha'];
            $numero    = isset($_POST['numero']) ? $formatNumeroLinha($_POST['numero']) : '';
            $descricao = isset($_POST['descricao']) ? $formatDescricaoLinha($_POST['descricao']) : (isset($_POST['linha']) ? $formatDescricaoLinha($_POST['linha']) : '');
            $ativo     = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($idLinha > 0 && ($numero !== '' || $descricao !== '')) {
                $colsLinha = $getColsLinha();
                if (in_array('numero', $colsLinha) && in_array('descricao', $colsLinha)) {
                    if (in_array('linha', $colsLinha)) {
                        $stmt = $pdo->prepare("UPDATE linha SET numero = :numero, descricao = :descricao, linha = :linha, ativo = :ativo WHERE idLinha = :id");
                        $stmt->execute([
                            ':numero'    => $numero,
                            ':descricao' => $descricao,
                            ':linha'     => $descricao !== '' ? $descricao : $numero,
                            ':ativo'     => $ativo,
                            ':id'        => $idLinha
                        ]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE linha SET numero = :numero, descricao = :descricao, ativo = :ativo WHERE idLinha = :id");
                        $stmt->execute([
                            ':numero'    => $numero,
                            ':descricao' => $descricao,
                            ':ativo'     => $ativo,
                            ':id'        => $idLinha
                        ]);
                    }
                } else {
                    $valLinha = ($numero !== '' ? $numero . ' - ' : '') . $descricao;
                    $stmt = $pdo->prepare("UPDATE linha SET linha = :linha, ativo = :ativo WHERE idLinha = :id");
                    $stmt->execute([':linha' => $valLinha, ':ativo' => $ativo, ':id' => $idLinha]);
                }
                $mensagemSucesso = "Linha ID #$idLinha atualizada com sucesso!";
            }
        } elseif ($action === 'delete_linha') {
            $idLinha = (int)$_POST['idLinha'];
            if ($idLinha > 0) {
                $stmt = $pdo->prepare("DELETE FROM linha WHERE idLinha = :id");
                $stmt->execute([':id' => $idLinha]);
                $mensagemSucesso = "Linha ID #$idLinha removida com sucesso!";
            }
        }

        // --- TABELA: LOCAIS ---
        elseif ($action === 'create_local') {
            $local  = trim($_POST['local']);
            $regiao = trim($_POST['regiao']);
            $ativo  = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($local !== '' && $regiao !== '') {
                $stmt = $pdo->prepare("INSERT INTO locais (local, regiao, ativo) VALUES (:local, :regiao, :ativo)");
                $stmt->execute([':local' => $local, ':regiao' => $regiao, ':ativo' => $ativo]);
                $mensagemSucesso = "Local '$local' cadastrado com sucesso!";
            } else {
                $mensagemErro = "Preencha todos os campos do Local.";
            }
        } elseif ($action === 'update_local') {
            $idLocal = (int)$_POST['idLocal'];
            $local   = trim($_POST['local']);
            $regiao  = trim($_POST['regiao']);
            $ativo   = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($idLocal > 0 && $local !== '' && $regiao !== '') {
                $stmt = $pdo->prepare("UPDATE locais SET local = :local, regiao = :regiao, ativo = :ativo WHERE idLocal = :id");
                $stmt->execute([':local' => $local, ':regiao' => $regiao, ':ativo' => $ativo, ':id' => $idLocal]);
                $mensagemSucesso = "Local ID #$idLocal atualizado com sucesso!";
            }
        } elseif ($action === 'delete_local') {
            $idLocal = (int)$_POST['idLocal'];
            if ($idLocal > 0) {
                $stmt = $pdo->prepare("DELETE FROM locais WHERE idLocal = :id");
                $stmt->execute([':id' => $idLocal]);
                $mensagemSucesso = "Local ID #$idLocal removido com sucesso!";
            }
        }

        // --- TABELA: USUÁRIOS ---
        elseif ($action === 'create_usuario') {
            $nome   = trim($_POST['nome']);
            $login  = trim($_POST['login']);
            $senha  = $_POST['senha'];
            $email  = trim($_POST['email']);
            $acesso = (int)$_POST['acesso'];
            $ativo  = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            
            if ($nome !== '' && $login !== '' && $senha !== '' && $email !== '') {
                $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
                $dataNow = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("INSERT INTO usuarios (nome, login, senha, email, data, acesso, ativo) VALUES (:nome, :login, :senha, :email, :data, :acesso, :ativo)");
                $stmt->execute([
                    ':nome'   => $nome,
                    ':login'  => $login,
                    ':senha'  => $hashSenha,
                    ':email'  => $email,
                    ':data'   => $dataNow,
                    ':acesso' => $acesso,
                    ':ativo'  => $ativo
                ]);
                $mensagemSucesso = "Usuário '$login' cadastrado com sucesso!";
            } else {
                $mensagemErro = "Preencha todos os campos do usuário.";
            }
        } elseif ($action === 'update_usuario') {
            $idusuarios = (int)$_POST['idusuarios'];
            $nome       = trim($_POST['nome']);
            $login      = trim($_POST['login']);
            $email      = trim($_POST['email']);
            $acesso     = (int)$_POST['acesso'];
            $ativo      = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            $senha      = $_POST['senha'];

            if ($idusuarios > 0 && $nome !== '' && $login !== '' && $email !== '') {
                if (!empty($senha)) {
                    $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, login = :login, senha = :senha, email = :email, acesso = :acesso, ativo = :ativo WHERE idusuarios = :id");
                    $stmt->execute([
                        ':nome'   => $nome,
                        ':login'  => $login,
                        ':senha'  => $hashSenha,
                        ':email'  => $email,
                        ':acesso' => $acesso,
                        ':ativo'  => $ativo,
                        ':id'     => $idusuarios
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, login = :login, email = :email, acesso = :acesso, ativo = :ativo WHERE idusuarios = :id");
                    $stmt->execute([
                        ':nome'   => $nome,
                        ':login'  => $login,
                        ':email'  => $email,
                        ':acesso' => $acesso,
                        ':ativo'  => $ativo,
                        ':id'     => $idusuarios
                    ]);
                }
                $mensagemSucesso = "Usuário ID #$idusuarios atualizado com sucesso!";
            }
        } elseif ($action === 'delete_usuario') {
            $idusuarios = (int)$_POST['idusuarios'];
            if ($idusuarios > 0) {
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE idusuarios = :id");
                $stmt->execute([':id' => $idusuarios]);
                $mensagemSucesso = "Usuário ID #$idusuarios removido com sucesso!";
            }
        }

        // --- TABELA: VEÍCULO ---
        elseif ($action === 'create_veiculo') {
            $prefixo = isset($_POST['prefixo']) ? trim((string)$_POST['prefixo']) : (isset($_POST['ordem']) ? trim((string)$_POST['ordem']) : '');
            $placa   = isset($_POST['placa']) ? trim((string)$_POST['placa']) : '';
            $ativo   = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($prefixo !== '') {
                $vTable = $getRealTable('veiculo', 'veiculos');
                $stmt = $pdo->prepare("INSERT INTO `$vTable` (prefixo, placa, ativo) VALUES (:prefixo, :placa, :ativo)");
                $stmt->execute([':prefixo' => $prefixo, ':placa' => $placa, ':ativo' => $ativo]);
                $mensagemSucesso = "Veículo prefixo '$prefixo' cadastrado com sucesso!";
            } else {
                $mensagemErro = "Preencha o prefixo do veículo.";
            }
        } elseif ($action === 'update_veiculo') {
            $idVeiculo = isset($_POST['idVeiculo']) ? (int)$_POST['idVeiculo'] : 0;
            $prefixo   = isset($_POST['prefixo']) ? trim((string)$_POST['prefixo']) : (isset($_POST['ordem']) ? trim((string)$_POST['ordem']) : '');
            $placa     = isset($_POST['placa']) ? trim((string)$_POST['placa']) : '';
            $ativo     = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($idVeiculo > 0 && $prefixo !== '') {
                $vTable = $getRealTable('veiculo', 'veiculos');
                $stmt = $pdo->prepare("UPDATE `$vTable` SET prefixo = :prefixo, placa = :placa, ativo = :ativo WHERE idVeiculo = :id");
                $stmt->execute([':prefixo' => $prefixo, ':placa' => $placa, ':ativo' => $ativo, ':id' => $idVeiculo]);
                $mensagemSucesso = "Veículo ID #$idVeiculo atualizado com sucesso!";
            }
        } elseif ($action === 'delete_veiculo') {
            $idVeiculo = isset($_POST['idVeiculo']) ? (int)$_POST['idVeiculo'] : 0;
            if ($idVeiculo > 0) {
                $vTable = $getRealTable('veiculo', 'veiculos');
                $stmt = $pdo->prepare("DELETE FROM `$vTable` WHERE idVeiculo = :id");
                $stmt->execute([':id' => $idVeiculo]);
                $mensagemSucesso = "Veículo ID #$idVeiculo removido com sucesso!";
            }
        }

        // --- TABELA: EMPRESAS / EMPRESA ---
        elseif ($action === 'create_empresa') {
            $nome      = trim($_POST['nome']);
            $cnpj      = trim($_POST['cnpj']);
            $telefone  = trim($_POST['telefone']);
            $idSistema = !empty($_POST['idSistema']) ? (int)$_POST['idSistema'] : null;
            if ($nome !== '') {
                $empTable = $getRealTable('empresa', 'empresas');

                $colsEmpresa = [];
                try {
                    $st = $pdo->query("SHOW COLUMNS FROM `$empTable`");
                    if ($st) $colsEmpresa = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {}

                $nameCol = in_array('nome', $colsEmpresa) ? 'nome' : (in_array('empresa', $colsEmpresa) ? 'empresa' : (!empty($colsEmpresa[1]) ? $colsEmpresa[1] : 'nome'));
                $cnpjCol = in_array('cnpj', $colsEmpresa) ? 'cnpj' : null;
                $telCol  = in_array('telefone', $colsEmpresa) ? 'telefone' : (in_array('tel', $colsEmpresa) ? 'tel' : null);
                
                $fkCol = null;
                if (in_array('idSistema', $colsEmpresa)) $fkCol = 'idSistema';
                elseif (in_array('id_sistema', $colsEmpresa)) $fkCol = 'id_sistema';
                elseif (in_array('idsistema', $colsEmpresa)) $fkCol = 'idsistema';
                elseif (in_array('sistema', $colsEmpresa)) $fkCol = 'sistema';
                else {
                    try {
                        $pdo->exec("ALTER TABLE `$empTable` ADD COLUMN idSistema INT NULL");
                        $fkCol = 'idSistema';
                    } catch (\Exception $e) {}
                }

                $fields = ["`$nameCol`"];
                $placeholders = [":nome"];
                $params = [':nome' => $nome];

                if ($cnpjCol) {
                    $fields[] = "`$cnpjCol`";
                    $placeholders[] = ":cnpj";
                    $params[':cnpj'] = $cnpj;
                }
                if ($telCol) {
                    $fields[] = "`$telCol`";
                    $placeholders[] = ":telefone";
                    $params[':telefone'] = $telefone;
                }
                if ($fkCol) {
                    $fields[] = "`$fkCol`";
                    $placeholders[] = ":idSistema";
                    $params[':idSistema'] = ($idSistema && $idSistema > 0) ? $idSistema : null;
                }

                $sql = "INSERT INTO `$empTable` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $mensagemSucesso = "Empresa '$nome' cadastrada com sucesso!";
            } else {
                $mensagemErro = "Preencha o nome da empresa.";
            }
        } elseif ($action === 'update_empresa') {
            $idEmpresa = (int)$_POST['idEmpresa'];
            $nome      = trim($_POST['nome']);
            $cnpj      = trim($_POST['cnpj']);
            $telefone  = trim($_POST['telefone']);
            $idSistema = !empty($_POST['idSistema']) ? (int)$_POST['idSistema'] : null;
            if ($idEmpresa > 0 && $nome !== '') {
                $empTable = $getRealTable('empresa', 'empresas');

                $colsEmpresa = [];
                try {
                    $st = $pdo->query("SHOW COLUMNS FROM `$empTable`");
                    if ($st) $colsEmpresa = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {}

                $idCol   = in_array('idEmpresa', $colsEmpresa) ? 'idEmpresa' : (in_array('id', $colsEmpresa) ? 'id' : (in_array('id_empresa', $colsEmpresa) ? 'id_empresa' : (!empty($colsEmpresa[0]) ? $colsEmpresa[0] : 'idEmpresa')));
                $nameCol = in_array('nome', $colsEmpresa) ? 'nome' : (in_array('empresa', $colsEmpresa) ? 'empresa' : (!empty($colsEmpresa[1]) ? $colsEmpresa[1] : 'nome'));
                $cnpjCol = in_array('cnpj', $colsEmpresa) ? 'cnpj' : null;
                $telCol  = in_array('telefone', $colsEmpresa) ? 'telefone' : (in_array('tel', $colsEmpresa) ? 'tel' : null);
                
                $fkCol = null;
                if (in_array('idSistema', $colsEmpresa)) $fkCol = 'idSistema';
                elseif (in_array('id_sistema', $colsEmpresa)) $fkCol = 'id_sistema';
                elseif (in_array('idsistema', $colsEmpresa)) $fkCol = 'idsistema';
                elseif (in_array('sistema', $colsEmpresa)) $fkCol = 'sistema';
                else {
                    try {
                        $pdo->exec("ALTER TABLE `$empTable` ADD COLUMN idSistema INT NULL");
                        $fkCol = 'idSistema';
                    } catch (\Exception $e) {}
                }

                $setParts = ["`$nameCol` = :nome"];
                $params = [':nome' => $nome, ':id' => $idEmpresa];

                if ($cnpjCol) {
                    $setParts[] = "`$cnpjCol` = :cnpj";
                    $params[':cnpj'] = $cnpj;
                }
                if ($telCol) {
                    $setParts[] = "`$telCol` = :telefone";
                    $params[':telefone'] = $telefone;
                }
                if ($fkCol) {
                    $setParts[] = "`$fkCol` = :idSistema";
                    $params[':idSistema'] = ($idSistema && $idSistema > 0) ? $idSistema : null;
                }

                $sql = "UPDATE `$empTable` SET " . implode(', ', $setParts) . " WHERE `$idCol` = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $mensagemSucesso = "Empresa ID #$idEmpresa atualizada com sucesso!";
            }
        } elseif ($action === 'delete_empresa') {
            $idEmpresa = (int)$_POST['idEmpresa'];
            if ($idEmpresa > 0) {
                $empTable = $getRealTable('empresa', 'empresas');

                $colsEmpresa = [];
                try {
                    $st = $pdo->query("SHOW COLUMNS FROM `$empTable`");
                    if ($st) $colsEmpresa = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {}

                $idCol = in_array('idEmpresa', $colsEmpresa) ? 'idEmpresa' : (in_array('id', $colsEmpresa) ? 'id' : (in_array('id_empresa', $colsEmpresa) ? 'id_empresa' : (!empty($colsEmpresa[0]) ? $colsEmpresa[0] : 'idEmpresa')));

                $stmt = $pdo->prepare("DELETE FROM `$empTable` WHERE `$idCol` = :id");
                $stmt->execute([':id' => $idEmpresa]);
                $mensagemSucesso = "Empresa ID #$idEmpresa removida com sucesso!";
            }
        }

        // --- TABELA: SISTEMAS / SISTEMA ---
        elseif ($action === 'create_sistema') {
            $nome      = trim($_POST['nome']);
            $descricao = trim($_POST['descricao']);
            $ativo     = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($nome !== '') {
                $sysTable = $getRealTable('sistema', 'sistemas');

                $colsSistema = [];
                try {
                    $st = $pdo->query("SHOW COLUMNS FROM `$sysTable`");
                    if ($st) $colsSistema = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {}

                if (!in_array('descricao', $colsSistema)) {
                    try {
                        $pdo->exec("ALTER TABLE `$sysTable` ADD COLUMN descricao VARCHAR(255) NULL");
                        $colsSistema[] = 'descricao';
                    } catch (\Exception $e) {}
                }
                if (!in_array('ativo', $colsSistema)) {
                    try {
                        $pdo->exec("ALTER TABLE `$sysTable` ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
                        $colsSistema[] = 'ativo';
                    } catch (\Exception $e) {}
                }

                $nameCol = in_array('nomeSistema', $colsSistema) ? 'nomeSistema' : (in_array('nomSistema', $colsSistema) ? 'nomSistema' : (in_array('nome', $colsSistema) ? 'nome' : (in_array('sistema', $colsSistema) ? 'sistema' : (!empty($colsSistema[1]) ? $colsSistema[1] : 'nomeSistema'))));
                $hasDesc = in_array('descricao', $colsSistema);

                if ($hasDesc) {
                    $stmt = $pdo->prepare("INSERT INTO `$sysTable` (`$nameCol`, `descricao`, `ativo`) VALUES (:nome, :descricao, :ativo)");
                    $stmt->execute([':nome' => $nome, ':descricao' => $descricao, ':ativo' => $ativo]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO `$sysTable` (`$nameCol`, `ativo`) VALUES (:nome, :ativo)");
                    $stmt->execute([':nome' => $nome, ':ativo' => $ativo]);
                }
                $mensagemSucesso = "Sistema '$nome' cadastrado com sucesso!";
            } else {
                $mensagemErro = "Preencha o nome do sistema.";
            }
        } elseif ($action === 'update_sistema') {
            $idSistema = (int)$_POST['idSistema'];
            $nome      = trim($_POST['nome']);
            $descricao = trim($_POST['descricao']);
            $ativo     = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
            if ($idSistema > 0 && $nome !== '') {
                $sysTable = $getRealTable('sistema', 'sistemas');

                $colsSistema = [];
                try {
                    $st = $pdo->query("SHOW COLUMNS FROM `$sysTable`");
                    if ($st) $colsSistema = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {}

                if (!in_array('descricao', $colsSistema)) {
                    try {
                        $pdo->exec("ALTER TABLE `$sysTable` ADD COLUMN descricao VARCHAR(255) NULL");
                        $colsSistema[] = 'descricao';
                    } catch (\Exception $e) {}
                }
                if (!in_array('ativo', $colsSistema)) {
                    try {
                        $pdo->exec("ALTER TABLE `$sysTable` ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
                        $colsSistema[] = 'ativo';
                    } catch (\Exception $e) {}
                }

                $idCol   = in_array('idSistema', $colsSistema) ? 'idSistema' : (in_array('id', $colsSistema) ? 'id' : (in_array('id_sistema', $colsSistema) ? 'id_sistema' : (!empty($colsSistema[0]) ? $colsSistema[0] : 'idSistema')));
                $nameCol = in_array('nomeSistema', $colsSistema) ? 'nomeSistema' : (in_array('nomSistema', $colsSistema) ? 'nomSistema' : (in_array('nome', $colsSistema) ? 'nome' : (in_array('sistema', $colsSistema) ? 'sistema' : (!empty($colsSistema[1]) ? $colsSistema[1] : 'nomeSistema'))));
                $hasDesc = in_array('descricao', $colsSistema);

                if ($hasDesc) {
                    $stmt = $pdo->prepare("UPDATE `$sysTable` SET `$nameCol` = :nome, `descricao` = :descricao, `ativo` = :ativo WHERE `$idCol` = :id");
                    $stmt->execute([':nome' => $nome, ':descricao' => $descricao, ':ativo' => $ativo, ':id' => $idSistema]);
                } else {
                    $stmt = $pdo->prepare("UPDATE `$sysTable` SET `$nameCol` = :nome, `ativo` = :ativo WHERE `$idCol` = :id");
                    $stmt->execute([':nome' => $nome, ':ativo' => $ativo, ':id' => $idSistema]);
                }
                $mensagemSucesso = "Sistema ID #$idSistema atualizado com sucesso!";
            }
        } elseif ($action === 'delete_sistema') {
            $idSistema = (int)$_POST['idSistema'];
            if ($idSistema > 0) {
                $sysTable = $getRealTable('sistema', 'sistemas');

                $colsSistema = [];
                try {
                    $st = $pdo->query("SHOW COLUMNS FROM `$sysTable`");
                    if ($st) $colsSistema = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {}

                $idCol = in_array('idSistema', $colsSistema) ? 'idSistema' : (in_array('id', $colsSistema) ? 'id' : (in_array('id_sistema', $colsSistema) ? 'id_sistema' : (!empty($colsSistema[0]) ? $colsSistema[0] : 'idSistema')));

                $stmt = $pdo->prepare("DELETE FROM `$sysTable` WHERE `$idCol` = :id");
                $stmt->execute([':id' => $idSistema]);
                $mensagemSucesso = "Sistema ID #$idSistema removido com sucesso!";
            }
        }
    } catch (Exception $e) {
        $mensagemErro = "Erro ao executar ação: " . $e->getMessage();
    }
}

// ==========================================
// CONSULTA DE DADOS (PROTEÇÃO SQLSTATE 42S22)
// ==========================================
$safeFetchAll = function($table, $preferredIdCol) use ($pdo) {
    try {
        return $pdo->query("SELECT * FROM `$table` ORDER BY `$preferredIdCol` ASC")->fetchAll();
    } catch (\PDOException $e) {
        try {
            return $pdo->query("SELECT * FROM `$table` ORDER BY 1 ASC")->fetchAll();
        } catch (\Exception $ex) {
            return [];
        }
    } catch (\Exception $e) {
        return [];
    }
};

$sysTable   = $getRealTable('sistema', 'sistemas');
$empTable   = $getRealTable('empresa', 'empresas');
$veicTable  = $getRealTable('veiculo', 'veiculos');
$linhaTable = $getRealTable('linha', 'linhas');

$linhas     = $safeFetchAll($linhaTable, 'idLinha');
$locais     = $safeFetchAll('locais', 'idLocal');
$usuarios   = $safeFetchAll('usuarios', 'idusuarios');
$veiculos   = $safeFetchAll($veicTable, 'idVeiculo');
$empresas   = $safeFetchAll($empTable, 'idEmpresa');
$sistemas   = $safeFetchAll($sysTable, 'idSistema');

$countLinhas     = count($linhas);
$countLocais     = count($locais);
$countUsuarios   = count($usuarios);
$countVeiculos   = count($veiculos);
$countEmpresas   = count($empresas);
$countSistemas   = count($sistemas);

// Criar mapa idSistema => nome para rápida associação na tabela de empresas
$sistemasMap = [];
foreach ($sistemas as $s) {
    $sId = isset($s['idSistema']) ? $s['idSistema'] : (isset($s['id']) ? $s['id'] : (isset($s['id_sistema']) ? $s['id_sistema'] : reset($s)));
    $sNome = isset($s['nomeSistema']) ? $s['nomeSistema'] : (isset($s['nomSistema']) ? $s['nomSistema'] : (isset($s['nome']) ? $s['nome'] : (isset($s['sistema']) ? $s['sistema'] : 'Sistema #' . $sId)));
    $sistemasMap[$sId] = $sNome;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>contaBuzu — Painel Administrativo</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Estilos do Popup de Filtro (Idênticos ao mobiApps) */
        .dropdown-menu-wrapper {
            position: relative !important;
            display: inline-block !important;
            user-select: none !important;
            -webkit-user-select: none !important;
        }

        .dropdown-menu-card {
            display: none !important;
            flex-direction: column !important;
            position: absolute !important;
            top: 100% !important;
            right: 0 !important;
            margin-top: 6px !important;
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.05) !important;
            min-width: 180px !important;
            z-index: 1000 !important;
            overflow: hidden !important;
            padding: 0 !important;
            animation: fadeIn 0.15s ease-out !important;
        }

        .dropdown-menu-card.show {
            display: flex !important;
        }

        .dropdown-item {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            width: 100% !important;
            padding: 12px 16px !important;
            font-size: 0.95rem !important;
            font-weight: 500 !important;
            color: #1e293b !important;
            background: transparent !important;
            border: none !important;
            text-align: left !important;
            cursor: pointer !important;
            transition: background 0.15s ease, color 0.15s ease !important;
        }

        .dropdown-item:hover {
            background-color: #f8fafc !important;
        }

        .dropdown-item.selected {
            font-weight: 700 !important;
            color: #0f172a !important;
            background-color: #f0f4fe !important;
        }

        .dropdown-item .check-icon {
            display: none !important;
            color: #0f172a !important;
            margin-left: 12px !important;
        }

        .dropdown-item.selected .check-icon {
            display: block !important;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Restauração do Design Moderno SaaS do Painel Administrativo */
        body.admin-body {
            background-color: #f8fafc !important;
            background-image: none !important;
            color: #1e293b !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }

        body.admin-body *, 
        body.admin-body input, 
        body.admin-body select, 
        body.admin-body button, 
        body.admin-body table, 
        body.admin-body th, 
        body.admin-body td {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }

        body.admin-body .dashboard-container {
            max-width: 1400px !important;
            margin: 20px auto !important;
            padding: 0 16px !important;
        }

        body.admin-body .dashboard-card {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 16px !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03) !important;
            overflow: hidden !important;
        }

        body.admin-body .dashboard-header {
            background: #ffffff !important;
            border-bottom: 1px solid #e2e8f0 !important;
            padding: 20px 40px !important;
            margin-bottom: 32px !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03) !important;
            color: #0f172a !important;
        }

        body.admin-body .dashboard-header h2 {
            font-size: 1.25rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            letter-spacing: normal !important;
            text-transform: none !important;
        }

        body.admin-body .dashboard-header .user-avatar {
            width: 50px !important;
            height: 50px !important;
            border-radius: 14px !important;
            background: linear-gradient(135deg, #003B7A 0%, #0054A6 100%) !important;
            color: white !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(0, 84, 166, 0.25) !important;
        }

        body.admin-body .tabs-navigation {
            display: flex !important;
            gap: 8px !important;
            border-bottom: 2px solid #e2e8f0 !important;
            margin-bottom: 20px !important;
            margin-top: 10px !important;
            background: transparent !important;
            padding: 0 !important;
        }

        body.admin-body .tab-btn {
            background: transparent !important;
            border: none !important;
            border-bottom: 3px solid transparent !important;
            border-radius: 0 !important;
            padding: 12px 24px !important;
            font-size: 0.95rem !important;
            font-weight: 600 !important;
            color: #64748b !important;
            cursor: pointer !important;
            text-transform: none !important;
            letter-spacing: normal !important;
            box-shadow: none !important;
            margin-bottom: -2px !important;
        }

        body.admin-body .tab-btn:hover {
            color: #0054A6 !important;
            background: rgba(226, 232, 240, 0.4) !important;
        }

        body.admin-body .tab-btn.active {
            color: #0054A6 !important;
            border-bottom-color: #0054A6 !important;
            border-top: none !important;
            background: #ffffff !important;
            box-shadow: none !important;
            font-weight: 700 !important;
        }

        body.admin-body .tab-badge {
            background: #eff6ff !important;
            color: #0054A6 !important;
            border: 1px solid #bfdbfe !important;
            font-size: 0.8rem !important;
            padding: 2px 8px !important;
            border-radius: 20px !important;
            font-weight: 700 !important;
        }

        body.admin-body table.apps-table,
        body.admin-body table {
            border: none !important;
            width: 100% !important;
            border-collapse: collapse !important;
        }

        body.admin-body table th {
            background: #f8fafc !important;
            color: #64748b !important;
            font-size: 0.78rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            border-bottom: 2px solid #cbd5e1 !important;
            border-top: none !important;
            border-left: none !important;
            border-right: 1px solid #cbd5e1 !important;
        }

        body.admin-body table th:last-child {
            border-right: none !important;
        }

        body.admin-body table td {
            border-bottom: 1px solid #e2e8f0 !important;
            border-top: none !important;
            border-left: none !important;
            border-right: 1px solid #e2e8f0 !important;
        }

        body.admin-body table td:last-child {
            border-right: none !important;
        }

        body.admin-body .badge-status-ativo {
            background-color: #dcfce7 !important;
            color: #15803d !important;
            padding: 4px 10px !important;
            border-radius: 20px !important;
            font-size: 0.78rem !important;
            font-weight: 700 !important;
            border: none !important;
        }

        body.admin-body .badge-status-inativo {
            background-color: #fee2e2 !important;
            color: #b91c1c !important;
            padding: 4px 10px !important;
            border-radius: 20px !important;
            font-size: 0.78rem !important;
            font-weight: 700 !important;
            border: none !important;
        }

        body.admin-body .btn-add-action {
            background: linear-gradient(135deg, #003B7A 0%, #0054A6 100%) !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 10px 18px !important;
            font-weight: 600 !important;
            font-size: 0.9rem !important;
            box-shadow: 0 4px 12px rgba(0, 84, 166, 0.25) !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            text-transform: none !important;
            letter-spacing: normal !important;
        }

        body.admin-body .btn-add-action:hover {
            background: linear-gradient(135deg, #002D5E 0%, #004385 100%) !important;
            transform: translateY(-1px) !important;
        }
    </style>
    <script>
        var activeTabId = '<?php echo htmlspecialchars($activeTab); ?>';
        var selectedStatusFilter = 'all';

        function switchTab(tabId, btn) {
            activeTabId = tabId;
            try {
                localStorage.setItem('contaBuzu_activeTab', tabId);
            } catch(e) {}

            var tabInputs = document.querySelectorAll('.input-active-tab');
            tabInputs.forEach(function(inp) {
                inp.value = tabId;
            });

            var contents = document.getElementsByClassName('tab-content');
            for (var i = 0; i < contents.length; i++) {
                contents[i].style.display = 'none';
            }
            var targetContent = document.getElementById(tabId);
            if (targetContent) targetContent.style.display = 'block';

            var buttons = document.getElementsByClassName('tab-btn');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].classList.remove('active');
            }
            if (btn) {
                btn.classList.add('active');
            } else {
                var targetBtn = document.querySelector(".tab-btn[onclick*='" + tabId + "']");
                if (targetBtn) targetBtn.classList.add('active');
            }

            var userFilterContainer = document.getElementById('user-status-filter-container');
            var dropdown = document.getElementById('status-dropdown');
            var filterableTabs = ['tab-linhas', 'tab-usuarios', 'tab-veiculos', 'tab-sistemas'];
            if (userFilterContainer) {
                userFilterContainer.style.display = (filterableTabs.indexOf(tabId) !== -1) ? 'inline-block' : 'none';
            }
            if (filterableTabs.indexOf(tabId) === -1 && dropdown) {
                dropdown.classList.remove('show');
            }

            var searchInput = document.getElementById('search-input');
            if (searchInput) handleSearch(searchInput.value);
        }

        document.addEventListener("DOMContentLoaded", function() {
            var isPost = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'true' : 'false'; ?>;
            var currentTab = '<?php echo htmlspecialchars($activeTab); ?>';

            if (!isPost) {
                try {
                    var savedTab = localStorage.getItem('contaBuzu_activeTab');
                    if (savedTab && document.getElementById(savedTab)) {
                        currentTab = savedTab;
                    }
                } catch(e) {}
            }

            var targetBtn = document.querySelector(".tab-btn[onclick*='" + currentTab + "']");
            if (targetBtn) {
                switchTab(currentTab, targetBtn);
            } else {
                switchTab(currentTab, null);
            }
        });

        // Abrir/fechar menu dropdown do filtro de status (copiado de mobiApps)
        function toggleFilterDropdown(event) {
            if (event) event.stopPropagation();
            var dropdown = document.getElementById('status-dropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }

        // Selecionar o filtro de status (copiado de mobiApps)
        function selectStatusFilter(val, label, element, event) {
            if (event) event.stopPropagation();
            selectedStatusFilter = val;

            var items = document.querySelectorAll('#status-dropdown .dropdown-item');
            items.forEach(function(item) {
                item.classList.remove('selected');
            });

            if (element) {
                element.classList.add('selected');
            }

            var dropdown = document.getElementById('status-dropdown');
            if (dropdown) {
                dropdown.classList.remove('show');
            }

            var searchInput = document.getElementById('search-input');
            handleSearch(searchInput ? searchInput.value : '');
        }

        // Fechar dropdown ao clicar fora (copiado de mobiApps)
        window.addEventListener('click', function(e) {
            var dropdown = document.getElementById('status-dropdown');
            var button = document.getElementById('status-filter-trigger');
            if (dropdown && button && !button.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Salvar HTML original das células para termo destacado e aplicar filtro inicial
        document.addEventListener("DOMContentLoaded", function() {
            // Ocultar mensagens de alerta (sucesso/erro) automaticamente após 3 segundos
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alertEl) {
                setTimeout(function() {
                    alertEl.classList.add('fade-out');
                    setTimeout(function() {
                        alertEl.style.display = 'none';
                    }, 500);
                }, 3000);
            });

            var cells = document.querySelectorAll(".apps-table tbody td");
            cells.forEach(function(cell) {
                if (cell.querySelector('button') || cell.querySelector('form') || cell.querySelector('a')) return;
                cell.setAttribute("data-original", cell.innerHTML);
            });

            // Aplica o filtro inicial se a aba de usuários estiver visível
            var searchInput = document.getElementById('search-input');
            handleSearch(searchInput ? searchInput.value : '');
        });


        // Pesquisa instantânea na aba ativa com filtro de status de usuário e destaque de termo
        function handleSearch(query) {
            query = (query || "").trim().toLowerCase();
            var activeTab = document.getElementById(activeTabId);
            if (!activeTab) return;

            var selectedStatus = selectedStatusFilter;

            var rows = activeTab.querySelectorAll("tbody tr");
            rows.forEach(function(row) {
                if (row.cells.length === 1) return;

                // Nas abas aplicáveis, aplica o filtro de status (Ativos, Inativos ou Todos)
                var filterableTabs = ['tab-linhas', 'tab-usuarios', 'tab-veiculos', 'tab-sistemas'];
                if (filterableTabs.indexOf(activeTabId) !== -1) {
                    var rowStatus = row.getAttribute('data-status');
                    if (rowStatus && selectedStatus !== 'all' && rowStatus !== selectedStatus) {
                        row.style.display = 'none';
                        return;
                    }
                }

                var matches = false;
                var cells = row.querySelectorAll("td");

                cells.forEach(function(cell) {
                    var original = cell.getAttribute("data-original");
                    if (!original) {
                        if (cell.textContent.toLowerCase().indexOf(query) !== -1) matches = true;
                        return;
                    }

                    if (query === "") {
                        cell.innerHTML = original;
                    } else {
                        var tempDiv = document.createElement("div");
                        tempDiv.innerHTML = original;
                        var text = tempDiv.textContent || tempDiv.innerText;

                        if (text.toLowerCase().indexOf(query) !== -1) {
                            matches = true;
                            var regex = new RegExp("(" + escapeRegExp(query) + ")", "gi");
                            cell.innerHTML = text.replace(regex, '<mark class="search-highlight">$1</mark>');
                        } else {
                            cell.innerHTML = original;
                        }
                    }
                });

                row.style.display = matches || query === "" ? "" : "none";
            });
        }

        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Controle dos Modais
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function openNewModalForActiveTab() {
            if (activeTabId === 'tab-linhas') {
                document.getElementById('linha-action').value = 'create_linha';
                document.getElementById('linha-id').value = '';
                document.getElementById('linha-numero').value = '';
                document.getElementById('linha-descricao').value = '';
                document.getElementById('linha-ativo').value = '1';
                document.getElementById('modal-linha-title').innerText = 'Nova Linha';
                openModal('modal-linha');
            } else if (activeTabId === 'tab-locais') {
                document.getElementById('local-action').value = 'create_local';
                document.getElementById('local-id').value = '';
                document.getElementById('local-input').value = '';
                document.getElementById('regiao-input').value = '';
                document.getElementById('local-ativo').value = '1';
                document.getElementById('modal-local-title').innerText = 'Novo Local';
                openModal('modal-local');
            } else if (activeTabId === 'tab-usuarios') {
                document.getElementById('usuario-action').value = 'create_usuario';
                document.getElementById('usuario-id').value = '';
                document.getElementById('usuario-nome').value = '';
                document.getElementById('usuario-login').value = '';
                document.getElementById('usuario-email').value = '';
                document.getElementById('usuario-senha').value = '';
                document.getElementById('usuario-senha').required = true;
                document.getElementById('senha-help').style.display = 'none';
                document.getElementById('usuario-acesso').value = '0';
                document.getElementById('usuario-ativo').value = '1';
                document.getElementById('modal-usuario-title').innerText = 'Novo Usuário';
                openModal('modal-usuario');
            } else if (activeTabId === 'tab-veiculos') {
                document.getElementById('veiculo-action').value = 'create_veiculo';
                document.getElementById('veiculo-id').value = '';
                document.getElementById('veiculo-prefixo').value = '';
                document.getElementById('veiculo-placa').value = '';
                document.getElementById('veiculo-ativo').value = '1';
                document.getElementById('modal-veiculo-title').innerText = 'Novo Veículo';
                openModal('modal-veiculo');
            } else if (activeTabId === 'tab-empresas') {
                document.getElementById('empresa-action').value = 'create_empresa';
                document.getElementById('empresa-id').value = '';
                document.getElementById('empresa-nome').value = '';
                document.getElementById('empresa-cnpj').value = '';
                document.getElementById('empresa-telefone').value = '';
                document.getElementById('modal-empresa-title').innerText = 'Nova Empresa';
                openModal('modal-empresa');
            } else if (activeTabId === 'tab-sistemas') {
                document.getElementById('sistema-action').value = 'create_sistema';
                document.getElementById('sistema-id').value = '';
                document.getElementById('sistema-nome').value = '';
                document.getElementById('sistema-descricao').value = '';
                document.getElementById('sistema-ativo').value = '1';
                document.getElementById('modal-sistema-title').innerText = 'Novo Sistema';
                openModal('modal-sistema');
            }
        }

        function formatLinhaNumeroInput(el) {
            var v = el.value.replace(/\D/g, '');
            if (v.length > 6) v = v.substring(0, 6);
            if (v.length > 4) {
                el.value = v.substring(0, 4) + '-' + v.substring(4);
            } else {
                el.value = v;
            }
        }

        function formatLinhaDescricaoInput(el) {
            el.value = el.value.toUpperCase();
        }

        function editLinha(data) {
            document.getElementById('linha-action').value = 'update_linha';
            document.getElementById('linha-id').value = data.idLinha || data.idlinha || data.id || data.id_linha || '';
            document.getElementById('linha-numero').value = data.numero || '';
            document.getElementById('linha-descricao').value = data.descricao || data.linha || '';
            var ativoVal = (data.ativo !== undefined && data.ativo !== null && data.ativo !== '') ? String(data.ativo) : '1';
            document.getElementById('linha-ativo').value = ativoVal;
            document.getElementById('modal-linha-title').innerText = 'Editar Linha';
            openModal('modal-linha');
        }

        function editLocal(data) {
            document.getElementById('local-action').value = 'update_local';
            document.getElementById('local-id').value = data.idLocal || data.idlocal || data.id || data.id_local || '';
            document.getElementById('local-input').value = data.local || '';
            document.getElementById('regiao-input').value = data.regiao || '';
            var ativoVal = (data.ativo !== undefined && data.ativo !== null && data.ativo !== '') ? String(data.ativo) : '1';
            document.getElementById('local-ativo').value = ativoVal;
            document.getElementById('modal-local-title').innerText = 'Editar Local';
            openModal('modal-local');
        }

        function editUsuario(data) {
            document.getElementById('usuario-action').value = 'update_usuario';
            document.getElementById('usuario-id').value = data.idusuarios || data.idusuario || data.id || data.id_usuario || '';
            document.getElementById('usuario-nome').value = data.nome || '';
            document.getElementById('usuario-login').value = data.login || '';
            document.getElementById('usuario-email').value = data.email || '';
            document.getElementById('usuario-senha').value = '';
            document.getElementById('usuario-senha').required = false;
            document.getElementById('senha-help').style.display = 'inline';
            document.getElementById('usuario-acesso').value = data.acesso !== undefined ? data.acesso : 0;
            var ativoVal = (data.ativo !== undefined && data.ativo !== null && data.ativo !== '') ? String(data.ativo) : '1';
            document.getElementById('usuario-ativo').value = ativoVal;
            document.getElementById('modal-usuario-title').innerText = 'Editar Usuário';
            openModal('modal-usuario');
        }

        function editVeiculo(data) {
            document.getElementById('veiculo-action').value = 'update_veiculo';
            document.getElementById('veiculo-id').value = data.idVeiculo || data.idveiculo || data.id || data.id_veiculo || '';
            document.getElementById('veiculo-prefixo').value = data.prefixo !== undefined ? data.prefixo : '';
            document.getElementById('veiculo-placa').value = data.placa !== undefined ? data.placa : '';
            var ativoVal = (data.ativo !== undefined && data.ativo !== null && data.ativo !== '') ? String(data.ativo) : '1';
            document.getElementById('veiculo-ativo').value = ativoVal;
            document.getElementById('modal-veiculo-title').innerText = 'Editar Veículo';
            openModal('modal-veiculo');
        }

        function editEmpresa(data) {
            document.getElementById('empresa-action').value = 'update_empresa';
            document.getElementById('empresa-id').value = data.idEmpresa || data.idempresa || data.id || data.id_empresa || '';
            document.getElementById('empresa-nome').value = data.nome || data.empresa || '';
            document.getElementById('empresa-cnpj').value = data.cnpj || '';
            document.getElementById('empresa-telefone').value = data.telefone || '';
            var sysSelect = document.getElementById('empresa-idsistema');
            if (sysSelect) sysSelect.value = data.idSistema || data.idsistemas || data.id_sistema || '';
            document.getElementById('modal-empresa-title').innerText = 'Editar Empresa';
            openModal('modal-empresa');
        }

        function editSistema(data) {
            document.getElementById('sistema-action').value = 'update_sistema';
            document.getElementById('sistema-id').value = data.idSistema || data.idsistemas || data.id || data.id_sistema || data.idsistema || '';
            document.getElementById('sistema-nome').value = data.nomeSistema || data.nomSistema || data.nome || data.sistema || '';
            document.getElementById('sistema-descricao').value = data.descricao || '';
            var ativoVal = (data.ativo !== undefined && data.ativo !== null && data.ativo !== '') ? String(data.ativo) : '1';
            document.getElementById('sistema-ativo').value = ativoVal;
            document.getElementById('modal-sistema-title').innerText = 'Editar Sistema';
            openModal('modal-sistema');
        }
    </script>
</head>
<body class="admin-body" style="background-color: #f8fafc; color: #1e293b;">

    <div class="dashboard-container">
        <div class="dashboard-card">

            <!-- Header do Painel mobiApps estilo SaaS -->
            <div class="dashboard-header" style="background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 20px 40px; margin-bottom: 32px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);">
                <div class="dashboard-user">
                    <div class="user-avatar" style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, #003B7A 0%, #0054A6 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; box-shadow: 0 4px 12px rgba(0, 84, 166, 0.25);">
                        <?php echo htmlspecialchars($initials); ?>
                    </div>
                    <div class="user-info">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0;"><?php echo htmlspecialchars($loggedNome); ?></h2>
                            <span class="badge-admin">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="12" height="12">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.746 3.746 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                                </svg>
                                Administrador
                            </span>
                        </div>
                        <p style="color: #64748b; font-size: 0.88rem; margin-top: 2px;">@<?php echo htmlspecialchars($loggedLogin); ?> • Painel de Gestão contaBuzu</p>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; align-items: center;">
                    <!-- Botão Voltar para Início -->
                    <a href="index.php" class="btn-secondary-action" style="text-decoration: none; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-weight: 600; font-size: 0.9rem; transition: all 0.2s;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                        </svg>
                        Voltar para Início
                    </a>

                    <!-- Botão Sair -->
                    <a href="logout.php" class="btn-logout" style="text-decoration: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
                        </svg>
                        Sair
                    </a>
                </div>
            </div>

            <!-- Título e Subtítulo Principal -->
            <div style="margin-bottom: 28px;">
                <h1 style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin-bottom: 6px; letter-spacing: -0.5px;">Painel Administrativo</h1>
                <p style="color: #64748b; font-size: 1rem;">Visão geral e gerenciamento completo dos registros de transporte e usuários no banco de dados contaBuzu.</p>
            </div>

            <!-- Abas de Navegação -->
            <div class="tabs-navigation" style="margin-top: 10px;">
                <button class="tab-btn active" onclick="switchTab('tab-linhas', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                    Linhas <span class="tab-badge"><?php echo $countLinhas; ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('tab-locais', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0z" />
                    </svg>
                    Locais <span class="tab-badge"><?php echo $countLocais; ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('tab-usuarios', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6 0 3.375 3.375 0 0 1 6 0zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0z" />
                    </svg>
                    Usuários <span class="tab-badge"><?php echo $countUsuarios; ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('tab-veiculos', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm9 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM3.75 9h16.5m-16.5 0L5.25 4.5h13.5L20.25 9m-16.5 0v6.75A1.5 1.5 0 0 0 5.25 17.25h13.5a1.5 1.5 0 0 0 1.5-1.5V9" />
                    </svg>
                    Veículos <span class="tab-badge"><?php echo $countVeiculos; ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('tab-empresas', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3a1.5 1.5 0 0 1 1.5-1.5h3a1.5 1.5 0 0 1 1.5 1.5v3" />
                    </svg>
                    Empresas <span class="tab-badge"><?php echo $countEmpresas; ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('tab-sistemas', this)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19 14.5M9.75 3.104c.251.023.501.05.75.082m5.25-.082c.25.023.5.05.75.082m-7.5 0a24.238 24.238 0 0 0-4.5.57M19 14.5A2.25 2.25 0 0 1 21.25 16.75v1.5A2.25 2.25 0 0 1 19 20.5H5a2.25 2.25 0 0 1-2.225-2.25v-1.5A2.25 2.25 0 0 1 5 14.5m14 0H5" />
                    </svg>
                    Sistemas <span class="tab-badge"><?php echo $countSistemas; ?></span>
                </button>
            </div>

            <!-- Toolbar de Busca e Filtros -->
            <div class="admin-toolbar" style="display: flex; gap: 12px; align-items: center; margin-bottom: 24px;">
                <div style="position: relative; flex: 1; min-width: 280px;">
                    <div style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #64748b; display: flex; align-items: center; pointer-events: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.602 10.602Z" />
                        </svg>
                    </div>
                    <input type="text" id="search-input" oninput="handleSearch(this.value)" placeholder="Pesquisar informação específica na aba ativa..." 
                           style="width: 100%; padding: 13px 16px 13px 48px; font-size: 0.95rem; border: 1px solid #cbd5e1; border-radius: 10px; outline: none; transition: all 0.2s ease; background: #ffffff;"
                           onfocus="this.style.borderColor='#0054A6'; this.style.boxShadow='0 0 0 4px rgba(0, 84, 166, 0.12)';"
                           onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';"
                           autocomplete="off">
                </div>

                <!-- Filtro de Status (Copiado de mobiApps - Estilizado em Popover de Lupa/Funil) -->
                <div id="user-status-filter-container" class="dropdown-menu-wrapper" style="display: none;">
                    <!-- Botão de Gatilho do Filtro -->
                    <button type="button" id="status-filter-trigger" onclick="toggleFilterDropdown(event)"
                            title="Filtrar por Status"
                            style="display: flex; align-items: center; justify-content: center; width: 49px; padding: 0; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; background-color: #ffffff; cursor: pointer; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); height: 49px; transition: border-color 0.15s ease;"
                            onmouseover="this.style.borderColor='#0054A6';"
                            onmouseout="this.style.borderColor='#cbd5e1';">
                        <!-- Ícone de Filtro (Funil) -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20" style="color: #64748b; pointer-events: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                        </svg>
                    </button>

                    <!-- Menu Dropdown Popover (Identico à imagem enviada) -->
                    <div id="status-dropdown" class="dropdown-menu-card">
                        <button type="button" class="dropdown-item" onclick="selectStatusFilter('1', 'Ativos', this, event)">
                            <span>Somente Ativos</span>
                            <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="16" height="16">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                        <button type="button" class="dropdown-item" onclick="selectStatusFilter('0', 'Inativos', this, event)">
                            <span>Somente Inativos</span>
                            <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="16" height="16">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                        <button type="button" class="dropdown-item selected" onclick="selectStatusFilter('all', 'Todos', this, event)">
                            <span>Todos</span>
                            <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="16" height="16">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mensagens Alerta -->
            <?php if ($mensagemSucesso): ?>
                <div class="alert alert-success" style="border-radius: 12px; margin-bottom: 24px; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.1);">
                    <span>✓ <?php echo htmlspecialchars($mensagemSucesso); ?></span>
                    <button class="alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($mensagemErro): ?>
                <div class="alert alert-danger" style="border-radius: 12px; margin-bottom: 24px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);">
                    <span>⚠ <?php echo htmlspecialchars($mensagemErro); ?></span>
                    <button class="alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>

            <!-- ABA 1: LINHAS -->
            <div id="tab-linhas" class="tab-content">
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 2px;">Linhas Cadastradas</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">Lista das linhas de ônibus e transporte ativas no sistema.</p>
                    </div>
                    <button type="button" class="btn-add-action" onclick="openNewModalForActiveTab()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Novo Registro
                    </button>
                </div>
                <div class="table-wrapper" style="overflow-x: auto; background: #ffffff; margin-bottom: 28px; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                    <table class="apps-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="text-align: left; padding: 14px 20px; width: 90px;">ID</th>
                                <th style="text-align: left; padding: 14px 20px; width: 140px;">Número</th>
                                <th style="text-align: left; padding: 14px 20px;">Descrição da Linha</th>
                                <th style="text-align: left; padding: 14px 20px;">Status</th>
                                <th style="text-align: center; padding: 14px 20px; width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($countLinhas > 0): ?>
                                <?php foreach ($linhas as $row): ?>
                                    <?php 
                                        $numDisplay = !empty($row['numero']) ? $formatNumeroLinha($row['numero']) : '—';
                                        $descDisplay = !empty($row['descricao']) ? $formatDescricaoLinha($row['descricao']) : (!empty($row['linha']) ? $formatDescricaoLinha($row['linha']) : '—');
                                        $isRowActive = !isset($row['ativo']) || (int)$row['ativo'] === 1;
                                    ?>
                                    <tr data-status="<?php echo $isRowActive ? '1' : '0'; ?>">
                                        <td style="padding: 14px 20px;"><span class="badge-id">#<?php echo $row['idLinha']; ?></span></td>
                                        <td style="padding: 14px 20px;"><span class="badge-numero" style="font-weight: 700; color: #0054A6; background: #eff6ff; padding: 4px 10px; border-radius: 6px; border: 1px solid #bfdbfe; display: inline-block;"><?php echo htmlspecialchars($numDisplay); ?></span></td>
                                        <td style="padding: 14px 20px; font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($descDisplay); ?></td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($isRowActive): ?>
                                                <span class="badge-status-active"><span class="status-dot"></span> Ativo</span>
                                            <?php else: ?>
                                                <span class="badge-status-inactive"><span class="status-dot"></span> Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px; text-align: center;">
                                            <button type="button" class="btn-action-icon btn-action-edit" onclick='editLinha(<?php echo json_encode($row); ?>)' title="Editar Linha">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir esta linha?');">
                                                <input type="hidden" name="action" value="delete_linha">
                                                <input type="hidden" name="idLinha" value="<?php echo $row['idLinha']; ?>">
                                                <button type="submit" class="btn-action-icon btn-action-delete" title="Excluir Linha">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="padding: 32px; text-align: center; color: #94a3b8;">
                                        <div style="font-size: 0.95rem; font-weight: 500;">Nenhuma linha cadastrada no momento.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 2: LOCAIS -->
            <div id="tab-locais" class="tab-content" style="display: none;">
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 2px;">Locais Cadastrados</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">Terminais e estações de atendimento da rede.</p>
                    </div>
                    <button type="button" class="btn-add-action" onclick="openNewModalForActiveTab()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Novo Registro
                    </button>
                </div>
                <div class="table-wrapper" style="overflow-x: auto; background: #ffffff; margin-bottom: 28px; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                    <table class="apps-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="text-align: left; padding: 14px 20px; width: 100px;">ID</th>
                                <th style="text-align: left; padding: 14px 20px;">Nome do Local</th>
                                <th style="text-align: left; padding: 14px 20px;">Região</th>
                                <th style="text-align: left; padding: 14px 20px;">Status</th>
                                <th style="text-align: center; padding: 14px 20px; width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($countLocais > 0): ?>
                                <?php foreach ($locais as $row): ?>
                                    <?php $isRowActive = !isset($row['ativo']) || (int)$row['ativo'] === 1; ?>
                                    <tr data-status="<?php echo $isRowActive ? '1' : '0'; ?>">
                                        <td style="padding: 14px 20px;"><span class="badge-id">#<?php echo $row['idLocal']; ?></span></td>
                                        <td style="padding: 14px 20px; font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($row['local']); ?></td>
                                        <td style="padding: 14px 20px;"><span class="badge-region"><?php echo htmlspecialchars($row['regiao']); ?></span></td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($isRowActive): ?>
                                                <span class="badge-status-active"><span class="status-dot"></span> Ativo</span>
                                            <?php else: ?>
                                                <span class="badge-status-inactive"><span class="status-dot"></span> Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px; text-align: center;">
                                            <button type="button" class="btn-action-icon btn-action-edit" onclick='editLocal(<?php echo json_encode($row); ?>)' title="Editar Local">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este local?');">
                                                <input type="hidden" name="action" value="delete_local">
                                                <input type="hidden" name="idLocal" value="<?php echo $row['idLocal']; ?>">
                                                <button type="submit" class="btn-action-icon btn-action-delete" title="Excluir Local">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="padding: 32px; text-align: center; color: #94a3b8;">
                                        <div style="font-size: 0.95rem; font-weight: 500;">Nenhum local cadastrado.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 3: USUÁRIOS -->
            <div id="tab-usuarios" class="tab-content" style="display: none;">
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 2px;">Usuários Cadastrados</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">Gerencie os privilégios e dados das contas de acesso.</p>
                    </div>
                    <button type="button" class="btn-add-action" onclick="openNewModalForActiveTab()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Novo Registro
                    </button>
                </div>
                <div class="table-wrapper" style="overflow-x: auto; background: #ffffff; margin-bottom: 28px; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                    <table class="apps-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="text-align: left; padding: 14px 20px; width: 80px;">ID</th>
                                <th style="text-align: left; padding: 14px 20px;">Nome</th>
                                <th style="text-align: left; padding: 14px 20px;">Usuário</th>
                                <th style="text-align: left; padding: 14px 20px;">E-mail</th>
                                <th style="text-align: left; padding: 14px 20px;">Nível de Acesso</th>
                                <th style="text-align: left; padding: 14px 20px;">Status</th>
                                <th style="text-align: left; padding: 14px 20px;">Data de Cadastro</th>
                                <th style="text-align: center; padding: 14px 20px; width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($countUsuarios > 0): ?>
                                <?php foreach ($usuarios as $row): ?>
                                    <?php $isRowActive = !isset($row['ativo']) || (int)$row['ativo'] === 1; ?>
                                    <tr data-status="<?php echo $isRowActive ? '1' : '0'; ?>">
                                        <td style="padding: 14px 20px;"><span class="badge-id">#<?php echo $row['idusuarios']; ?></span></td>
                                        <td style="padding: 14px 20px; font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($row['nome']); ?></td>
                                        <td style="padding: 14px 20px;"><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #475569;">@<?php echo htmlspecialchars($row['login']); ?></code></td>
                                        <td style="padding: 14px 20px; color: #475569;"><?php echo htmlspecialchars($row['email']); ?></td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($row['acesso'] == 1): ?>
                                                <span class="badge-admin">Administrador</span>
                                            <?php else: ?>
                                                <span class="badge-user">Usuário Padrão</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($isRowActive): ?>
                                                <span class="badge-status-active"><span class="status-dot"></span> Ativo</span>
                                            <?php else: ?>
                                                <span class="badge-status-inactive"><span class="status-dot"></span> Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px; color: #64748b; font-size: 0.85rem;"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($row['data']))); ?></td>
                                        <td style="padding: 14px 20px; text-align: center;">
                                            <button type="button" class="btn-action-icon btn-action-edit" onclick='editUsuario(<?php echo json_encode($row); ?>)' title="Editar Usuário">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja excluir este usuário?');">
                                                <input type="hidden" name="action" value="delete_usuario">
                                                <input type="hidden" name="idusuarios" value="<?php echo $row['idusuarios']; ?>">
                                                <button type="submit" class="btn-action-icon btn-action-delete" title="Excluir Usuário">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="padding: 32px; text-align: center; color: #94a3b8;">
                                        <div style="font-size: 0.95rem; font-weight: 500;">Nenhum usuário cadastrado.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 4: VEÍCULOS -->
            <div id="tab-veiculos" class="tab-content" style="display: none;">
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 2px;">Veículos Cadastrados</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">Frota de veículos e número de ordem registrado.</p>
                    </div>
                    <button type="button" class="btn-add-action" onclick="openNewModalForActiveTab()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Novo Registro
                    </button>
                </div>
                <div class="table-wrapper" style="overflow-x: auto; background: #ffffff; margin-bottom: 28px; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                    <table class="apps-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="text-align: left; padding: 14px 20px; width: 100px;">ID</th>
                                <th style="text-align: left; padding: 14px 20px; width: 140px;">Prefixo</th>
                                <th style="text-align: left; padding: 14px 20px;">Placa do Veículo</th>
                                <th style="text-align: left; padding: 14px 20px;">Status</th>
                                <th style="text-align: center; padding: 14px 20px; width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($countVeiculos > 0): ?>
                                <?php foreach ($veiculos as $row): ?>
                                    <?php $isRowActive = !isset($row['ativo']) || (int)$row['ativo'] === 1; ?>
                                    <tr data-status="<?php echo $isRowActive ? '1' : '0'; ?>">
                                        <td style="padding: 14px 20px;"><span class="badge-id">#<?php echo $row['idVeiculo']; ?></span></td>
                                        <td style="padding: 14px 20px;"><span class="badge-numero" style="font-weight: 700; color: #0054A6; background: #eff6ff; padding: 4px 10px; border-radius: 6px; border: 1px solid #bfdbfe; display: inline-block;"><?php echo htmlspecialchars($row['prefixo']); ?></span></td>
                                        <td style="padding: 14px 20px; font-weight: 600; color: #1e293b;"><?php echo !empty($row['placa']) ? htmlspecialchars($row['placa']) : '—'; ?></td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($isRowActive): ?>
                                                <span class="badge-status-active"><span class="status-dot"></span> Ativo</span>
                                            <?php else: ?>
                                                <span class="badge-status-inactive"><span class="status-dot"></span> Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px; text-align: center;">
                                            <button type="button" class="btn-action-icon btn-action-edit" onclick='editVeiculo(<?php echo json_encode($row); ?>)' title="Editar Veículo">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja excluir este veículo?');">
                                                <input type="hidden" name="action" value="delete_veiculo">
                                                <input type="hidden" name="idVeiculo" value="<?php echo $row['idVeiculo']; ?>">
                                                <button type="submit" class="btn-action-icon btn-action-delete" title="Excluir Veículo">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="padding: 32px; text-align: center; color: #94a3b8;">
                                        <div style="font-size: 0.95rem; font-weight: 500;">Nenhum veículo cadastrado.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 5: EMPRESAS -->
            <div id="tab-empresas" class="tab-content" style="display: none;">
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 2px;">Empresas Cadastradas</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">Concessionárias e empresas operadoras de transporte.</p>
                    </div>
                    <button type="button" class="btn-add-action" onclick="openNewModalForActiveTab()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Novo Registro
                    </button>
                </div>
                <div class="table-wrapper" style="overflow-x: auto; background: #ffffff; margin-bottom: 28px; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                    <table class="apps-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="text-align: left; padding: 14px 20px; width: 90px;">ID</th>
                                <th style="text-align: left; padding: 14px 20px;">Nome da Empresa</th>
                                <th style="text-align: left; padding: 14px 20px;">CNPJ</th>
                                <th style="text-align: left; padding: 14px 20px;">Telefone</th>
                                <th style="text-align: left; padding: 14px 20px;">Sistema Utilizado</th>
                                <th style="text-align: center; padding: 14px 20px; width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($countEmpresas > 0): ?>
                                <?php foreach ($empresas as $row): ?>
                                    <?php
                                        $eId       = isset($row['idEmpresa']) ? $row['idEmpresa'] : (isset($row['id']) ? $row['id'] : (isset($row['id_empresa']) ? $row['id_empresa'] : reset($row)));
                                        $eNome     = isset($row['nome']) ? $row['nome'] : (isset($row['empresa']) ? $row['empresa'] : '—');
                                        $eCnpj     = isset($row['cnpj']) ? $row['cnpj'] : '—';
                                        $eTelefone = isset($row['telefone']) ? $row['telefone'] : '—';
                                        $eSysId    = isset($row['idSistema']) ? $row['idSistema'] : (isset($row['id_sistema']) ? $row['id_sistema'] : null);
                                        $eSysNome  = ($eSysId && isset($sistemasMap[$eSysId])) ? $sistemasMap[$eSysId] : (isset($row['sistema']) ? $row['sistema'] : '—');
                                    ?>
                                    <tr>
                                        <td style="padding: 14px 20px;"><span class="badge-id">#<?php echo htmlspecialchars($eId); ?></span></td>
                                        <td style="padding: 14px 20px; font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($eNome); ?></td>
                                        <td style="padding: 14px 20px; color: #475569;"><?php echo htmlspecialchars($eCnpj ?: '—'); ?></td>
                                        <td style="padding: 14px 20px; color: #475569;"><?php echo htmlspecialchars($eTelefone ?: '—'); ?></td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($eSysNome !== '—' && !empty($eSysNome)): ?>
                                                <span class="badge-system" style="background: #e0f2fe; color: #0369a1; font-weight: 600; padding: 4px 10px; border-radius: 6px; font-size: 0.85rem; border: 1px solid #bae6fd; display: inline-block;">
                                                    ⚡ <?php echo htmlspecialchars($eSysNome); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 0.85rem;">— Não informado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px; text-align: center;">
                                            <button type="button" class="btn-action-icon btn-action-edit" onclick='editEmpresa(<?php echo json_encode($row); ?>)' title="Editar Empresa">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja excluir esta empresa?');">
                                                <input type="hidden" name="action" value="delete_empresa">
                                                <input type="hidden" name="idEmpresa" value="<?php echo htmlspecialchars($eId); ?>">
                                                <button type="submit" class="btn-action-icon btn-action-delete" title="Excluir Empresa">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="padding: 32px; text-align: center; color: #94a3b8;">
                                        <div style="font-size: 0.95rem; font-weight: 500;">Nenhuma empresa cadastrada.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ABA 6: SISTEMAS -->
            <div id="tab-sistemas" class="tab-content" style="display: none;">
                <div style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 2px;">Sistemas Cadastrados</h3>
                        <p style="color: #64748b; font-size: 0.88rem;">Sistemas e softwares operacionais de bilhetagem e gestão de transporte.</p>
                    </div>
                    <button type="button" class="btn-add-action" onclick="openNewModalForActiveTab()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Novo Registro
                    </button>
                </div>
                <div class="table-wrapper" style="overflow-x: auto; background: #ffffff; margin-bottom: 28px; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                    <table class="apps-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="text-align: left; padding: 14px 20px; width: 90px;">ID</th>
                                <th style="text-align: left; padding: 14px 20px;">Nome do Sistema</th>
                                <th style="text-align: left; padding: 14px 20px;">Descrição</th>
                                <th style="text-align: left; padding: 14px 20px;">Status</th>
                                <th style="text-align: center; padding: 14px 20px; width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($countSistemas > 0): ?>
                                <?php foreach ($sistemas as $row): ?>
                                    <?php
                                        $sId   = isset($row['idSistema']) ? $row['idSistema'] : (isset($row['id']) ? $row['id'] : (isset($row['id_sistema']) ? $row['id_sistema'] : reset($row)));
                                        $sNome = isset($row['nomeSistema']) ? $row['nomeSistema'] : (isset($row['nomSistema']) ? $row['nomSistema'] : (isset($row['nome']) ? $row['nome'] : (isset($row['sistema']) ? $row['sistema'] : '—')));
                                        $sDesc = isset($row['descricao']) ? $row['descricao'] : '—';
                                        $isRowActive = !isset($row['ativo']) || (int)$row['ativo'] === 1;
                                    ?>
                                    <tr data-status="<?php echo $isRowActive ? '1' : '0'; ?>">
                                        <td style="padding: 14px 20px;"><span class="badge-id">#<?php echo htmlspecialchars($sId); ?></span></td>
                                        <td style="padding: 14px 20px; font-weight: 700; color: #0284c7;"><?php echo htmlspecialchars($sNome); ?></td>
                                        <td style="padding: 14px 20px; color: #475569;"><?php echo htmlspecialchars($sDesc ?: '—'); ?></td>
                                        <td style="padding: 14px 20px;">
                                            <?php if ($isRowActive): ?>
                                                <span class="badge-status-active"><span class="status-dot"></span> Ativo</span>
                                            <?php else: ?>
                                                <span class="badge-status-inactive"><span class="status-dot"></span> Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 20px; text-align: center;">
                                            <button type="button" class="btn-action-icon btn-action-edit" onclick='editSistema(<?php echo json_encode($row); ?>)' title="Editar Sistema">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                </svg>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Deseja excluir este sistema?');">
                                                <input type="hidden" name="action" value="delete_sistema">
                                                <input type="hidden" name="idSistema" value="<?php echo htmlspecialchars($sId); ?>">
                                                <button type="submit" class="btn-action-icon btn-action-delete" title="Excluir Sistema">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="padding: 32px; text-align: center; color: #94a3b8;">
                                        <div style="font-size: 0.95rem; font-weight: 500;">Nenhum sistema cadastrado.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAIS DE CRIAÇÃO / EDIÇÃO                 -->
    <!-- ========================================== -->

    <!-- Modal Linha -->
    <div id="modal-linha" class="modal-backdrop">
        <div class="modal-card-premium">
            <form method="POST">
                <input type="hidden" name="active_tab" class="input-active-tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="modal-header-premium">
                    <div class="modal-header-title">
                        <div class="modal-header-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                            </svg>
                        </div>
                        <h3 id="modal-linha-title">Nova Linha</h3>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('modal-linha')">&times;</button>
                </div>
                <div class="modal-body-premium">
                    <input type="hidden" name="action" id="linha-action" value="create_linha">
                    <input type="hidden" name="idLinha" id="linha-id">
                    <div class="form-group">
                        <label for="linha-numero" class="form-label">Número da Linha</label>
                        <input type="text" name="numero" id="linha-numero" class="form-control" placeholder="Ex: 0101-00" maxlength="7" oninput="formatLinhaNumeroInput(this)" required>
                        <small style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 4px;">Formato: 0000-00</small>
                    </div>
                    <div class="form-group">
                        <label for="linha-descricao" class="form-label">Nome / Descrição da Linha</label>
                        <input type="text" name="descricao" id="linha-descricao" class="form-control" placeholder="Ex: LAPA - GRAÇA" oninput="formatLinhaDescricaoInput(this)" style="text-transform: uppercase;" required>
                        <small style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 4px;">Formato: ORIGEM - DESTINO (Ex: LAPA - GRAÇA)</small>
                    </div>
                    <div class="form-group">
                        <label for="linha-ativo" class="form-label">Status da Linha</label>
                        <select name="ativo" id="linha-ativo" class="form-control">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer-premium">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-linha')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Registro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Local -->
    <div id="modal-local" class="modal-backdrop">
        <div class="modal-card-premium">
            <form method="POST">
                <input type="hidden" name="active_tab" class="input-active-tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="modal-header-premium">
                    <div class="modal-header-title">
                        <div class="modal-header-icon" style="background: #ccfbf1; color: #0d9488;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0z" />
                            </svg>
                        </div>
                        <h3 id="modal-local-title">Novo Local</h3>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('modal-local')">&times;</button>
                </div>
                <div class="modal-body-premium">
                    <input type="hidden" name="action" id="local-action" value="create_local">
                    <input type="hidden" name="idLocal" id="local-id">
                    <div class="form-group">
                        <label for="local-input" class="form-label">Nome do Local</label>
                        <input type="text" name="local" id="local-input" class="form-control" placeholder="Ex: Estação Lapa" required>
                    </div>
                    <div class="form-group">
                        <label for="regiao-input" class="form-label">Região</label>
                        <input type="text" name="regiao" id="regiao-input" class="form-control" placeholder="Ex: Centro" required>
                    </div>
                    <div class="form-group">
                        <label for="local-ativo" class="form-label">Status do Local</label>
                        <select name="ativo" id="local-ativo" class="form-select">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer-premium">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-local')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Registro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Usuário -->
    <div id="modal-usuario" class="modal-backdrop">
        <div class="modal-card-premium">
            <form method="POST">
                <input type="hidden" name="active_tab" class="input-active-tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="modal-header-premium">
                    <div class="modal-header-title">
                        <div class="modal-header-icon" style="background: #faf5ff; color: #7c3aed;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0zM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                        </div>
                        <h3 id="modal-usuario-title">Novo Usuário</h3>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('modal-usuario')">&times;</button>
                </div>
                <div class="modal-body-premium">
                    <input type="hidden" name="action" id="usuario-action" value="create_usuario">
                    <input type="hidden" name="idusuarios" id="usuario-id">
                    <div class="form-group">
                        <label for="usuario-nome" class="form-label">Nome</label>
                        <input type="text" name="nome" id="usuario-nome" class="form-control" placeholder="Nome do usuário" required>
                    </div>
                    <div class="form-group">
                        <label for="usuario-login" class="form-label">Usuário</label>
                        <input type="text" name="login" id="usuario-login" class="form-control" placeholder="usuario" required>
                    </div>
                    <div class="form-group">
                        <label for="usuario-email" class="form-label">E-mail</label>
                        <input type="email" name="email" id="usuario-email" class="form-control" placeholder="usuario@email.com" required>
                    </div>
                    <div class="form-group">
                        <label for="usuario-senha" class="form-label">Senha <small id="senha-help" style="color: #64748b; display:none;">(deixe em branco para não alterar)</small></label>
                        <input type="password" name="senha" id="usuario-senha" class="form-control" placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label for="usuario-acesso" class="form-label">Nível de Acesso</label>
                        <select name="acesso" id="usuario-acesso" class="form-control">
                            <option value="0">Usuário Padrão</option>
                            <option value="1">Administrador</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="usuario-ativo" class="form-label">Status da Conta</label>
                        <select name="ativo" id="usuario-ativo" class="form-control">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer-premium">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-usuario')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Usuário</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Veículo -->
    <div id="modal-veiculo" class="modal-backdrop">
        <div class="modal-card-premium">
            <form method="POST">
                <input type="hidden" name="active_tab" class="input-active-tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="modal-header-premium">
                    <div class="modal-header-title">
                        <div class="modal-header-icon" style="background: #ffedd5; color: #ea580c;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm9 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM3.75 9h16.5m-16.5 0L5.25 4.5h13.5L20.25 9m-16.5 0v6.75A1.5 1.5 0 0 0 5.25 17.25h13.5a1.5 1.5 0 0 0 1.5-1.5V9" />
                            </svg>
                        </div>
                        <h3 id="modal-veiculo-title">Novo Veículo</h3>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('modal-veiculo')">&times;</button>
                </div>
                <div class="modal-body-premium">
                    <input type="hidden" name="action" id="veiculo-action" value="create_veiculo">
                    <input type="hidden" name="idVeiculo" id="veiculo-id">
                    <div class="form-group">
                        <label for="veiculo-prefixo" class="form-label">Prefixo do Veículo</label>
                        <input type="text" name="prefixo" id="veiculo-prefixo" class="form-control" placeholder="Ex: 1001" required maxlength="5">
                    </div>
                    <div class="form-group">
                        <label for="veiculo-placa" class="form-label">Placa do Veículo</label>
                        <input type="text" name="placa" id="veiculo-placa" class="form-control" placeholder="Ex: ABC-1234">
                    </div>
                    <div class="form-group">
                        <label for="veiculo-ativo" class="form-label">Status do Veículo</label>
                        <select name="ativo" id="veiculo-ativo" class="form-control">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer-premium">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-veiculo')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Veículo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Empresa -->
    <div id="modal-empresa" class="modal-backdrop">
        <div class="modal-card-premium">
            <form method="POST">
                <input type="hidden" name="active_tab" class="input-active-tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="modal-header-premium">
                    <div class="modal-header-title">
                        <div class="modal-header-icon" style="background: #e0f2fe; color: #0284c7;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3a1.5 1.5 0 0 1 1.5-1.5h3a1.5 1.5 0 0 1 1.5 1.5v3" />
                            </svg>
                        </div>
                        <h3 id="modal-empresa-title">Nova Empresa</h3>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('modal-empresa')">&times;</button>
                </div>
                <div class="modal-body-premium">
                    <input type="hidden" name="action" id="empresa-action" value="create_empresa">
                    <input type="hidden" name="idEmpresa" id="empresa-id">
                    <div class="form-group">
                        <label for="empresa-nome" class="form-label">Nome da Empresa</label>
                        <input type="text" name="nome" id="empresa-nome" class="form-control" placeholder="Ex: Viação Salvador Ltda" required>
                    </div>
                    <div class="form-group">
                        <label for="empresa-cnpj" class="form-label">CNPJ</label>
                        <input type="text" name="cnpj" id="empresa-cnpj" class="form-control" placeholder="Ex: 00.000.000/0001-00">
                    </div>
                    <div class="form-group">
                        <label for="empresa-telefone" class="form-label">Telefone de Contato</label>
                        <input type="text" name="telefone" id="empresa-telefone" class="form-control" placeholder="Ex: (71) 3333-4444">
                    </div>
                    <div class="form-group">
                        <label for="empresa-idsistema" class="form-label">Sistema Utilizado (Foreign Key)</label>
                        <select name="idSistema" id="empresa-idsistema" class="form-control">
                            <option value="">-- Nenhum / Não Informado --</option>
                            <?php foreach ($sistemas as $s): ?>
                                <?php 
                                    $sysOptId = isset($s['idSistema']) ? $s['idSistema'] : (isset($s['id']) ? $s['id'] : (isset($s['id_sistema']) ? $s['id_sistema'] : reset($s)));
                                    $sysOptNome = isset($s['nomeSistema']) ? $s['nomeSistema'] : (isset($s['nomSistema']) ? $s['nomSistema'] : (isset($s['nome']) ? $s['nome'] : (isset($s['sistema']) ? $s['sistema'] : 'Sistema #' . $sysOptId)));
                                ?>
                                <option value="<?php echo htmlspecialchars($sysOptId); ?>"><?php echo htmlspecialchars($sysOptNome); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer-premium">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-empresa')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Empresa</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Sistema -->
    <div id="modal-sistema" class="modal-backdrop">
        <div class="modal-card-premium">
            <form method="POST">
                <input type="hidden" name="active_tab" class="input-active-tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="modal-header-premium">
                    <div class="modal-header-title">
                        <div class="modal-header-icon" style="background: #e0f2fe; color: #0284c7;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="22" height="22">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19 14.5M9.75 3.104c.251.023.501.05.75.082m5.25-.082c.25.023.5.05.75.082m-7.5 0a24.238 24.238 0 0 0-4.5.57M19 14.5A2.25 2.25 0 0 1 21.25 16.75v1.5A2.25 2.25 0 0 1 19 20.5H5a2.25 2.25 0 0 1-2.225-2.25v-1.5A2.25 2.25 0 0 1 5 14.5m14 0H5" />
                            </svg>
                        </div>
                        <h3 id="modal-sistema-title">Novo Sistema</h3>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('modal-sistema')">&times;</button>
                </div>
                <div class="modal-body-premium">
                    <input type="hidden" name="action" id="sistema-action" value="create_sistema">
                    <input type="hidden" name="idSistema" id="sistema-id">
                    <div class="form-group">
                        <label for="sistema-nome" class="form-label">Nome do Sistema</label>
                        <input type="text" name="nome" id="sistema-nome" class="form-control" placeholder="Ex: mobiApps, TransCard" required>
                    </div>
                    <div class="form-group">
                        <label for="sistema-descricao" class="form-label">Descrição / Observação</label>
                        <input type="text" name="descricao" id="sistema-descricao" class="form-control" placeholder="Ex: Sistema de Bilhetagem e Validação">
                    </div>
                    <div class="form-group">
                        <label for="sistema-ativo" class="form-label">Status do Sistema</label>
                        <select name="ativo" id="sistema-ativo" class="form-control">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer-premium">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-sistema')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Sistema</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
