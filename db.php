<?php
/**
 * Conexão com o Banco de Dados contaBuzu
 */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');

$charset = 'utf8mb4';
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Configurações de conexão: variáveis de ambiente > MySQL local > servidor em rede
$dbConfigs = [];
if (getenv('DB_HOST')) {
    $dbConfigs[] = [
        'host' => getenv('DB_HOST'),
        'db'   => getenv('DB_NAME') ?: 'contaBuzu',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    ];
}
$dbConfigs[] = [
    'host' => '127.0.0.1',
    'db'   => 'contaBuzu',
    'user' => 'root',
    'pass' => 'T@r2707',
];
$dbConfigs[] = [
    'host' => '192.168.45.1',
    'db'   => 'contaBuzu',
    'user' => 'root',
    'pass' => '',
];
$dbConfigs[] = [
    'host' => '127.0.0.1',
    'db'   => 'contaBuzu',
    'user' => 'root',
    'pass' => '',
];

$host = '127.0.0.1';
$pdo = null;
$lastException = null;

foreach ($dbConfigs as $cfg) {
    try {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['db']};charset=$charset";
        $opts = $options + [PDO::ATTR_TIMEOUT => 2];
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
        $host = $cfg['host'];
        break;
    } catch (\PDOException $ex) {
        $lastException = $ex;
    }
}

try {
    if (!$pdo) {
        throw $lastException ?: new \PDOException("Não foi possível estabelecer conexão com o banco de dados.");
    }
    
    // Garantir que a coluna 'ativo' existe na tabela usuarios
    try {
        $stmtUser = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'ativo'");
        if ($stmtUser && count($stmtUser->fetchAll()) === 0) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (\PDOException $ex) {
        // Ignora se a tabela ainda não tiver sido criada
    } catch (\Exception $ex) {
        // Ignora em versões PHP/PDO legadas
    }

    // Garantir que a coluna 'cargo' existe na tabela usuarios
    try {
        $stmtCargo = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cargo'");
        if ($stmtCargo && count($stmtCargo->fetchAll()) === 0) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN cargo VARCHAR(50) NULL DEFAULT 'Pesquisador' AFTER acesso");
            $pdo->exec("UPDATE usuarios SET cargo = 'Administrador' WHERE acesso = 1");
            $pdo->exec("UPDATE usuarios SET cargo = 'Gerente' WHERE acesso = 2");
            $pdo->exec("UPDATE usuarios SET cargo = 'Pesquisador' WHERE acesso = 0 OR acesso IS NULL");
        }
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que as colunas 'numero', 'descricao' e 'ativo' existem na tabela linha
    try {
        $stmtLinha = $pdo->query("SHOW COLUMNS FROM linha");
        if ($stmtLinha) {
            $colsLinha = $stmtLinha->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($colsLinha) && count($colsLinha) > 0) {
                if (!in_array('numero', $colsLinha)) {
                    $pdo->exec("ALTER TABLE linha ADD COLUMN numero VARCHAR(50) NULL AFTER idLinha");
                }
                if (!in_array('descricao', $colsLinha)) {
                    $pdo->exec("ALTER TABLE linha ADD COLUMN descricao VARCHAR(255) NULL");
                    if (in_array('linha', $colsLinha)) {
                        $pdo->exec("UPDATE linha SET descricao = linha WHERE descricao IS NULL OR descricao = ''");
                    }
                }
                if (!in_array('idEmpresa', $colsLinha)) {
                    $pdo->exec("ALTER TABLE linha ADD COLUMN idEmpresa INT NULL");
                }
                if (!in_array('ativo', $colsLinha)) {
                    $pdo->exec("ALTER TABLE linha ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
                }
            }
        }
    } catch (\PDOException $ex) {
        // Ignora se a tabela ainda não tiver sido criada
    } catch (\Exception $ex) {
        // Ignora em versões PHP/PDO legadas
    }

    // Garantir que as colunas 'ativo' e 'idEmpresa' existem na tabela veiculo/veiculos
    try {
        $targetVeicTable = 'veiculo';
        $checkVeicSingular = $pdo->query("SHOW TABLES LIKE 'veiculo'");
        if ($checkVeicSingular && count($checkVeicSingular->fetchAll()) > 0) {
            $targetVeicTable = 'veiculo';
        } else {
            $checkVeicPlural = $pdo->query("SHOW TABLES LIKE 'veiculos'");
            if ($checkVeicPlural && count($checkVeicPlural->fetchAll()) > 0) {
                $targetVeicTable = 'veiculos';
            }
        }
        $stmtVeic = $pdo->query("SHOW COLUMNS FROM `$targetVeicTable`");
        if ($stmtVeic) {
            $colsVeic = $stmtVeic->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($colsVeic) && count($colsVeic) > 0) {
                if (!in_array('idEmpresa', $colsVeic)) {
                    $pdo->exec("ALTER TABLE `$targetVeicTable` ADD COLUMN idEmpresa INT NULL");
                }
                if (!in_array('ativo', $colsVeic)) {
                    $pdo->exec("ALTER TABLE `$targetVeicTable` ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
                }
            }
        }
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que a tabela empresas/empresa existe e possui as colunas necessárias
    try {
        $targetEmpTable = 'empresas';
        $checkEmpSingular = $pdo->query("SHOW TABLES LIKE 'empresa'");
        if ($checkEmpSingular && count($checkEmpSingular->fetchAll()) > 0) {
            $targetEmpTable = 'empresa';
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS empresas (
                idEmpresa INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(255) NOT NULL,
                cnpj VARCHAR(20) NULL,
                telefone VARCHAR(20) NULL,
                idSistema INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        $stmtEmpresaCols = $pdo->query("SHOW COLUMNS FROM `$targetEmpTable`");
        if ($stmtEmpresaCols) {
            $colsE = $stmtEmpresaCols->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($colsE) && count($colsE) > 0) {
                if (!in_array('idEmpresa', $colsE) && !in_array('id', $colsE) && !in_array('id_empresa', $colsE)) {
                    $pdo->exec("ALTER TABLE `$targetEmpTable` ADD COLUMN idEmpresa INT AUTO_INCREMENT PRIMARY KEY FIRST");
                }
                if (!in_array('nome', $colsE) && !in_array('empresa', $colsE)) {
                    $pdo->exec("ALTER TABLE `$targetEmpTable` ADD COLUMN nome VARCHAR(255) NOT NULL DEFAULT ''");
                }
                if (!in_array('cnpj', $colsE)) {
                    $pdo->exec("ALTER TABLE `$targetEmpTable` ADD COLUMN cnpj VARCHAR(20) NULL");
                }
                if (!in_array('telefone', $colsE)) {
                    $pdo->exec("ALTER TABLE `$targetEmpTable` ADD COLUMN telefone VARCHAR(20) NULL");
                }
                if (!in_array('idSistema', $colsE) && !in_array('id_sistema', $colsE) && !in_array('idsistema', $colsE) && !in_array('sistema', $colsE)) {
                    $pdo->exec("ALTER TABLE `$targetEmpTable` ADD COLUMN idSistema INT NULL");
                }
            }
        }
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que a tabela sistema (singular) existe e possui as colunas necessárias
    try {
        $targetSysTable = 'sistema';
        $checkSingular = $pdo->query("SHOW TABLES LIKE 'sistema'");
        if ($checkSingular && count($checkSingular->fetchAll()) > 0) {
            $targetSysTable = 'sistema';
        } else {
            $checkPlural = $pdo->query("SHOW TABLES LIKE 'sistemas'");
            if ($checkPlural && count($checkPlural->fetchAll()) > 0) {
                $targetSysTable = 'sistemas';
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS sistema (
                    idSistema INT AUTO_INCREMENT PRIMARY KEY,
                    nomeSistema VARCHAR(255) NOT NULL,
                    descricao VARCHAR(255) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        }

        $stmtSistema = $pdo->query("SHOW COLUMNS FROM `$targetSysTable`");
        if ($stmtSistema) {
            $colsSistema = $stmtSistema->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($colsSistema) && count($colsSistema) > 0) {
                if (!in_array('idSistema', $colsSistema) && !in_array('id', $colsSistema) && !in_array('id_sistema', $colsSistema)) {
                    $pdo->exec("ALTER TABLE `$targetSysTable` ADD COLUMN idSistema INT AUTO_INCREMENT PRIMARY KEY FIRST");
                }
                if (!in_array('nomeSistema', $colsSistema) && !in_array('nomSistema', $colsSistema) && !in_array('nome', $colsSistema) && !in_array('sistema', $colsSistema)) {
                    $pdo->exec("ALTER TABLE `$targetSysTable` ADD COLUMN nomeSistema VARCHAR(255) NOT NULL DEFAULT ''");
                }
                if (!in_array('descricao', $colsSistema)) {
                    $pdo->exec("ALTER TABLE `$targetSysTable` ADD COLUMN descricao VARCHAR(255) NULL");
                }
                if (!in_array('ativo', $colsSistema)) {
                    $pdo->exec("ALTER TABLE `$targetSysTable` ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
                }
            }
        }
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que a tabela locais existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS locais (
            idLocal INT AUTO_INCREMENT PRIMARY KEY,
            local VARCHAR(255) NOT NULL,
            regiao VARCHAR(255) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmtLocCol = $pdo->query("SHOW COLUMNS FROM locais");
        if ($stmtLocCol) {
            $colsLoc = $stmtLocCol->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($colsLoc) && !in_array('ativo', $colsLoc)) {
                $pdo->exec("ALTER TABLE locais ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
            }
        }
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que a tabela pesquisa existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pesquisa (
            idPesquisa INT AUTO_INCREMENT PRIMARY KEY,
            idUsuario INT NOT NULL,
            idLinha INT NULL,
            idVeiculo INT NULL,
            idLocal INT NULL,
            idEmpresa INT NULL,
            viagem VARCHAR(50) DEFAULT 'Ida',
            condTempo VARCHAR(50) DEFAULT 'Bom',
            idSistema INT NULL DEFAULT 5,
            tipoTransporte VARCHAR(50) DEFAULT 'stco',
            tipoContagem VARCHAR(50) DEFAULT 'Percentual',
            tipoOperacao VARCHAR(50) DEFAULT 'Embarque',
            turno VARCHAR(50) DEFAULT 'Matutino',
            status VARCHAR(50) NOT NULL DEFAULT 'em_andamento',
            totalPassageiros FLOAT NOT NULL DEFAULT 0,
            dataInicio DATETIME NOT NULL,
            dataFim DATETIME NULL,
            KEY idx_pesquisa_usuario_status (idUsuario, status),
            KEY idx_pesquisa_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmtPesq = $pdo->query("SHOW COLUMNS FROM pesquisa");
        if ($stmtPesq) {
            $colsP = $stmtPesq->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('idUsuario', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN idUsuario INT NULL");
            if (!in_array('idVeiculo', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN idVeiculo INT NULL");
            if (!in_array('idEmpresa', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN idEmpresa INT NULL");
            if (!in_array('status', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'em_andamento'");
            if (!in_array('totalPassageiros', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN totalPassageiros FLOAT NOT NULL DEFAULT 0");
            if (!in_array('dataInicio', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN dataInicio DATETIME NULL");
            if (!in_array('dataFim', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN dataFim DATETIME NULL");
            if (!in_array('turno', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN turno VARCHAR(50) NULL DEFAULT 'Matutino'");
            if (!in_array('tipoTransporte', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN tipoTransporte VARCHAR(50) NULL DEFAULT 'stco'");
            if (!in_array('viagem', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN viagem VARCHAR(50) NULL DEFAULT 'Ida'");
            if (!in_array('condTempo', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN condTempo VARCHAR(50) NULL DEFAULT 'Bom'");
            if (!in_array('idSistema', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN idSistema INT NULL DEFAULT 5");
            if (!in_array('tipoContagem', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN tipoContagem VARCHAR(50) NULL DEFAULT 'Percentual'");
            if (!in_array('tipoOperacao', $colsP)) $pdo->exec("ALTER TABLE pesquisa ADD COLUMN tipoOperacao VARCHAR(50) NULL DEFAULT 'Embarque'");
        }
    } catch (\Exception $ex) {}

    // Garantir que a tabela veiculoPesquisado existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS veiculoPesquisado (
            idVeiculoPesquisado INT AUTO_INCREMENT PRIMARY KEY,
            idPesquisa INT NOT NULL,
            idVeiculo INT NOT NULL,
            ordem INT NULL DEFAULT 0,
            KEY idx_vp_pesquisa (idPesquisa),
            KEY idx_vp_veiculo (idVeiculo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Exception $ex) {}

    // Garantir colunas na tabela contagem (justificativa e idVeiculoPesquisado anulável)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS contagem (
            idContagem INT AUTO_INCREMENT PRIMARY KEY,
            idPesquisa INT NOT NULL,
            idVeiculoPesquisado INT NULL,
            idUsuario INT NOT NULL,
            tipo VARCHAR(50) NOT NULL DEFAULT 'incremento',
            quantidade FLOAT NOT NULL DEFAULT 0,
            justificativa TEXT NULL,
            dataHora DATETIME NOT NULL,
            KEY idx_contagem_pesquisa (idPesquisa),
            KEY idx_contagem_usuario (idUsuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmtCont = $pdo->query("SHOW COLUMNS FROM contagem");
        if ($stmtCont) {
            $colsC = $stmtCont->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('justificativa', $colsC)) {
                $pdo->exec("ALTER TABLE contagem ADD COLUMN justificativa TEXT NULL");
            }
            if (!in_array('tipo', $colsC)) {
                $pdo->exec("ALTER TABLE contagem ADD COLUMN tipo VARCHAR(50) NOT NULL DEFAULT 'incremento'");
            }
            if (!in_array('dataHora', $colsC)) {
                $pdo->exec("ALTER TABLE contagem ADD COLUMN dataHora DATETIME NULL");
            }
            try {
                $pdo->exec("ALTER TABLE contagem MODIFY COLUMN idVeiculoPesquisado INT(11) NULL");
            } catch (\Exception $exN) {}
        }
    } catch (\Exception $ex) {}

    // Garantir que a tabela solicitacoesCorrecao existe para o fluxo de aprovação de supervisores
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacoesCorrecao (
            idSolicitacao INT AUTO_INCREMENT PRIMARY KEY,
            idContagem INT NOT NULL,
            idUsuarioSolicitante INT NOT NULL,
            idUsuarioDestino INT NOT NULL,
            quantidade INT NOT NULL,
            justificativa TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pendente',
            motivoResposta TEXT NULL,
            idUsuarioResposta INT NULL,
            dataSolicitacao DATETIME NOT NULL,
            dataResposta DATETIME NULL,
            KEY idx_destino_status (idUsuarioDestino, status),
            KEY idx_solicitante (idUsuarioSolicitante),
            KEY idx_contagem (idContagem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }
} catch (\PDOException $e) {
    die("
    <div style='font-family: Arial, sans-serif; padding: 40px; text-align: center; color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; margin: 40px auto; max-width: 600px;'>
        <h2>Erro de Conexão com o Banco de Dados</h2>
        <p>Não foi possível conectar ao banco de dados <strong>contaBuzu</strong> no servidor <code>$host</code>.</p>
        <p><small style='color: #666;'>" . htmlspecialchars($e->getMessage()) . "</small></p>
    </div>
    ");
}
