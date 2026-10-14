<?php
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
session_start();

require_once 'db.php';

$erro = '';
$sucesso = '';

$loginInput = isset($_GET['login']) ? trim($_GET['login']) : '';

if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $sucesso = 'Você saiu com sucesso do sistema.';
}

if (isset($_GET['erro']) && $_GET['erro'] === 'inativo') {
    $erro = 'Sua conta de usuário foi desativada. Entre em contato com o administrador.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = isset($_POST['login']) ? trim($_POST['login']) : '';
    $password   = isset($_POST['password']) ? $_POST['password'] : '';

    if ($loginInput === '' || $password === '') {
        $erro = 'Por favor, preencha todos os campos.';
    } else {
        try {
            // Consulta usuário na tabela usuarios do banco contaBuzu (incluindo o status ativo)
            $stmt = $pdo->prepare("SELECT idusuarios, nome, login, senha, email, acesso, ativo FROM usuarios WHERE login = ? LIMIT 1");
            $stmt->execute([$loginInput]);
            $user = $stmt->fetch();

            if ($user) {
                // Verifica se a conta está inativa
                if (isset($user['ativo']) && (int)$user['ativo'] === 0) {
                    $erro = 'Sua conta de usuário está inativa. Entre em contato com o administrador.';
                } else {
                    // Suporta tanto senhas criptografadas quanto texto puro (legado)
                    $senhaValida = password_verify($password, $user['senha']) || ($password === $user['senha']);

                    if ($senhaValida) {
                        $_SESSION['usuario_id'] = $user['idusuarios'];
                        $_SESSION['login']      = $user['login'];
                        $_SESSION['nome']       = $user['nome'];
                        $_SESSION['email']      = $user['email'];
                        $_SESSION['acesso']     = $user['acesso'];
                        $_SESSION['ativo']      = (int)$user['ativo'];

                        header('Location: index.php?login=success');
                        exit;
                    } else {
                        $erro = 'Usuário ou senha inválidos.';
                    }
                }
            } else {
                $erro = 'Usuário ou senha inválidos.';
            }
        } catch (PDOException $e) {
            $erro = 'Erro ao consultar banco de dados: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>contaBuzu | Autenticação</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-body">

    <!-- Círculos Decorativos do Background mobiApps -->
    <div class="bg-decoration bg-decoration-1"></div>
    <div class="bg-decoration bg-decoration-2"></div>
    <div class="bg-decoration bg-decoration-3"></div>

    <div class="auth-container">
        <div class="auth-card <?php echo $erro ? 'shake' : ''; ?>">
            
            <div class="auth-header">
                <div class="logo-wrapper">
                    <!-- Ícone de Ônibus contaBuzu / Transporte -->
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M4 16c0 .88.39 1.67 1 2.22V20c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h8v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1.78c.61-.55 1-1.34 1-2.22V6c0-3.5-3.58-4-8-4s-8 .5-8 4v10zm3.5 1c-.83 0-1.5-.67-1.5-1.5S6.67 14 7.5 14s1.5.67 1.5 1.5S8.33 17 7.5 17zm9 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm1.5-6H6V6h12v5z"/>
                    </svg>
                </div>
                <div class="brand-tag">contaBuzu</div>
                <h1 class="auth-title">contaBuzu</h1>
                <p class="auth-subtitle">Acesso Restrito — Identifique-se para entrar</p>
            </div>

            <?php if ($sucesso): ?>
                <div class="alert alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span><?php echo htmlspecialchars($sucesso); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($erro): ?>
                <div class="alert alert-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    <span><?php echo htmlspecialchars($erro); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" id="form-login">
                <div class="form-group">
                    <label for="login" class="form-label">Usuário</label>
                    <div class="input-wrapper">
                        <input type="text" id="login" name="login" class="form-control" placeholder="Digite seu usuário" value="<?php echo htmlspecialchars($loginInput); ?>" required autofocus autocomplete="username">
                        <!-- Ícone de Usuário -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Senha</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        <!-- Ícone de Cadeado -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btn-entrar">
                    <span>Entrar</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </button>
            </form>

            <div class="auth-footer">
                <p>NTI — Núcleo de Tecnologia da Informação</p>
                <span>contaBuzu &copy; <?php echo date('Y'); ?> • Todos os direitos reservados</span>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alertEl) {
                setTimeout(function() {
                    alertEl.classList.add('fade-out');
                    setTimeout(function() {
                        alertEl.style.display = 'none';
                    }, 500);
                }, 3000);
            });
        });
    </script>
</body>
</html>
