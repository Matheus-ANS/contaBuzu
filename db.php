<?php
/**
 * Conexão com o Banco de Dados contaBuzu
 */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');

// Carregador nativo de variáveis do arquivo .env (compatível com PHP 5.6+)
if (!function_exists('carregarEnvContaBuzu')) {
    function carregarEnvContaBuzu($caminhoEnv) {
        if (!file_exists($caminhoEnv) || !is_readable($caminhoEnv)) {
            return false;
        }
        $linhas = @file($caminhoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($linhas === false) return false;
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if (empty($linha) || strpos($linha, '#') === 0 || strpos($linha, ';') === 0) {
                continue;
            }
            if (strpos($linha, '=') !== false) {
                list($chave, $valor) = explode('=', $linha, 2);
                $chave = trim($chave);
                $valor = trim($valor);
                if (preg_match('/^(["\'])(.*)\1$/', $valor, $matches)) {
                    $valor = $matches[2];
                }
                if (!empty($chave)) {
                    putenv("{$chave}={$valor}");
                    $_ENV[$chave] = $valor;
                    $_SERVER[$chave] = $valor;
                }
            }
        }
        return true;
    }
}

// Carrega as credenciais a partir do arquivo .env local (se existir)
carregarEnvContaBuzu(__DIR__ . '/.env');

$charset = 'utf8mb4';
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Configurações de conexão: variáveis do .env ou ambiente
$envHost = getenv('DB_HOST') ?: (isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : (isset($_SERVER['DB_HOST']) ? $_SERVER['DB_HOST'] : ''));
$envName = getenv('DB_NAME') ?: (isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : (isset($_SERVER['DB_NAME']) ? $_SERVER['DB_NAME'] : 'contaBuzu'));
$envUser = getenv('DB_USER') ?: (isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : (isset($_SERVER['DB_USER']) ? $_SERVER['DB_USER'] : 'root'));
$envPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : (isset($_SERVER['DB_PASS']) ? $_SERVER['DB_PASS'] : ''));
$envPort = getenv('DB_PORT') ?: (isset($_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : (isset($_SERVER['DB_PORT']) ? $_SERVER['DB_PORT'] : '3306'));

$dbConfigs = [];
if (!empty($envHost)) {
    $dbConfigs[] = [
        'host' => $envHost,
        'db'   => $envName,
        'user' => $envUser,
        'pass' => $envPass,
        'port' => $envPort,
    ];
}

// Fallbacks de conexão local padrão (sem credenciais sensíveis hardcoded)
$dbConfigs[] = [
    'host' => '127.0.0.1',
    'db'   => $envName ?: 'contaBuzu',
    'user' => $envUser ?: 'root',
    'pass' => $envPass,
    'port' => $envPort ?: '3306',
];
$dbConfigs[] = [
    'host' => 'localhost',
    'db'   => $envName ?: 'contaBuzu',
    'user' => $envUser ?: 'root',
    'pass' => $envPass,
    'port' => $envPort ?: '3306',
];

$host = '127.0.0.1';
$pdo = null;
$lastException = null;

foreach ($dbConfigs as $cfg) {
    try {
        $portStr = !empty($cfg['port']) ? ";port={$cfg['port']}" : '';
        $dsn = "mysql:host={$cfg['host']}{$portStr};dbname={$cfg['db']};charset=$charset";
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
        $targetEmpTable = 'empresa';
        $checkEmpSingular = $pdo->query("SHOW TABLES LIKE 'empresa'");
        if ($checkEmpSingular && count($checkEmpSingular->fetchAll()) > 0) {
            $targetEmpTable = 'empresa';
        } else {
            $targetEmpTable = 'empresas';
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
