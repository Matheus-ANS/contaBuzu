<?php
/**
 * Conexão com o Banco de Dados contaBuzu
 */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');

$host    = '192.168.45.1';
$db      = 'contaBuzu';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
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

    // Garantir que a tabela contagens existe e possui as colunas necessárias
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS contagens (
            idContagem INT AUTO_INCREMENT PRIMARY KEY,
            idUsuario INT NOT NULL,
            idLinha INT NULL,
            idVeiculo INT NULL,
            idLocal INT NULL,
            sentido VARCHAR(50) DEFAULT 'Ida',
            status ENUM('em_andamento', 'concluida') DEFAULT 'em_andamento',
            totalPassageiros INT DEFAULT 0,
            dataInicio DATETIME NOT NULL,
            dataFim DATETIME NULL,
            condicaoTempo VARCHAR(50) NULL DEFAULT 'bom',
            tipoTransporte VARCHAR(50) NULL DEFAULT 'stco',
            tipoContagem VARCHAR(50) NULL DEFAULT 'percentual',
            tipoOperacao VARCHAR(50) NULL DEFAULT 'embarque',
            turno VARCHAR(50) NULL DEFAULT 'Matutino',
            KEY idx_usuario_status (idUsuario, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmtCont = $pdo->query("SHOW COLUMNS FROM contagens");
        if ($stmtCont) {
            $colsCont = $stmtCont->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($colsCont) && count($colsCont) > 0) {
                if (!in_array('idEmpresa', $colsCont)) {
                    $pdo->exec("ALTER TABLE contagens ADD COLUMN idEmpresa INT NULL AFTER idLocal");
                }
                if (!in_array('condicaoTempo', $colsCont)) {
                    $pdo->exec("ALTER TABLE contagens ADD COLUMN condicaoTempo VARCHAR(50) NULL DEFAULT 'bom'");
                }
                if (!in_array('tipoTransporte', $colsCont)) {
                    $pdo->exec("ALTER TABLE contagens ADD COLUMN tipoTransporte VARCHAR(50) NULL DEFAULT 'stco'");
                }
                if (!in_array('tipoContagem', $colsCont)) {
                    $pdo->exec("ALTER TABLE contagens ADD COLUMN tipoContagem VARCHAR(50) NULL DEFAULT 'percentual'");
                }
                if (!in_array('tipoOperacao', $colsCont)) {
                    $pdo->exec("ALTER TABLE contagens ADD COLUMN tipoOperacao VARCHAR(50) NULL DEFAULT 'embarque'");
                }
                if (!in_array('turno', $colsCont)) {
                    $pdo->exec("ALTER TABLE contagens ADD COLUMN turno VARCHAR(50) NULL DEFAULT 'Matutino'");
                }
                try {
                    $pdo->exec("ALTER TABLE contagens MODIFY COLUMN status VARCHAR(50) DEFAULT 'em_andamento'");
                } catch (\Exception $exMod) {}
            }
        }
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que a tabela contagem_registros existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS contagem_registros (
            idRegistro INT AUTO_INCREMENT PRIMARY KEY,
            idContagem INT NOT NULL,
            idUsuario INT NOT NULL,
            tipo VARCHAR(50) NOT NULL DEFAULT 'incremento',
            quantidade INT NOT NULL,
            justificativa TEXT NULL,
            dataHora DATETIME NOT NULL,
            KEY idx_contagem (idContagem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE contagem_registros MODIFY COLUMN tipo VARCHAR(50) NOT NULL DEFAULT 'incremento'");
        } catch (\Exception $exMod) {}
    } catch (\PDOException $ex) {
    } catch (\Exception $ex) {
    }

    // Garantir que a tabela solicitacoes_correcao existe para o fluxo de aprovação de supervisores
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacoes_correcao (
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
