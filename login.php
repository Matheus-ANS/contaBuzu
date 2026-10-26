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
            // Consulta usuário na tabela usuarios do banco contaBuzu (incluindo o status ativo e cargo)
            $stmt = $pdo->prepare("SELECT idusuarios, nome, login, senha, email, acesso, cargo, ativo FROM usuarios WHERE login = ? LIMIT 1");
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
                        $_SESSION['acesso']     = (int)$user['acesso'];
                        $_SESSION['cargo']      = isset($user['cargo']) ? $user['cargo'] : '';
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

    <!-- Botão de Alternância de Tema Flutuante -->
    <div class="theme-toggle-floating">
        <button type="button" id="theme-toggle-btn" class="btn-theme-switch" title="Alternar entre modo claro e escuro" aria-label="Alternar tema">
            <span class="theme-icon-light">☀️</span>
            <span class="theme-icon-dark" style="display: none;">🌙</span>
            <span class="theme-label-text">Tema</span>
        </button>
    </div>

    <!-- Círculos Decorativos do Background com Gradientes Modernos -->
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
                <div class="brand-tag">
                    <span class="pulse-dot-mini"></span>
                    contaBuzu 2.5
                </div>
                <h1 class="auth-title">contaBuzu</h1>
                <p class="auth-subtitle">Sistema de Gestão & Contagem de Passageiros</p>
                <div class="auth-status-badge">
                    <span class="status-pulse-indicator"></span>
                    <span>Servidor Operacional • Online</span>
                </div>
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
                        <input type="text" id="login" name="login" class="form-control" placeholder="Digite seu usuário de acesso" value="<?php echo htmlspecialchars($loginInput); ?>" required autofocus autocomplete="username">
                        <!-- Ícone de Usuário -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label for="password" class="form-label" style="margin-bottom: 0;">Senha</label>
                        <span id="caps-lock-warning" style="display: none; font-size: 0.76rem; color: #ea580c; font-weight: 700; align-items: center; gap: 4px;">
                            ⚠️ Caps Lock ativado
                        </span>
                    </div>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-control input-with-action" placeholder="••••••••" required autocomplete="current-password">
                        <!-- Ícone de Cadeado -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <!-- Botão Revelar/Ocultar Senha -->
                        <button type="button" id="btn-toggle-password" class="input-action-btn" title="Visualizar ou ocultar senha" aria-label="Visualizar senha">
                            <svg class="eye-open-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            <svg class="eye-closed-icon" style="display: none;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Lembrar Usuário -->
                <div class="form-options-row">
                    <label class="custom-checkbox-container">
                        <input type="checkbox" id="remember-me">
                        <span class="custom-checkmark"></span>
                        <span class="checkbox-label">Lembrar meu usuário</span>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="btn-entrar">
                    <span class="btn-text">Entrar no Sistema</span>
                    <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                    <span class="btn-loader" style="display: none;">
                        <svg class="spinner-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Autenticando...
                    </span>
                </button>
            </form>

            <div class="auth-footer">
                <div class="auth-footer-badge">NTI • Núcleo de Tecnologia da Informação</div>
                <span>contaBuzu &copy; <?php echo date('Y'); ?> • Sistema Integrado de Mobilidade</span>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Gerenciador de Tema (Dark / Light)
            var currentTheme = localStorage.getItem("contabuzu_theme");
            if (!currentTheme) {
                currentTheme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
            }
            applyTheme(currentTheme);

            var themeBtn = document.getElementById("theme-toggle-btn");
            if (themeBtn) {
                themeBtn.addEventListener("click", function() {
                    var newTheme = document.documentElement.getAttribute("data-theme") === "dark" ? "light" : "dark";
                    localStorage.setItem("contabuzu_theme", newTheme);
                    applyTheme(newTheme);
                });
            }

            function applyTheme(theme) {
                if (theme === "dark") {
                    document.documentElement.setAttribute("data-theme", "dark");
                    document.body.setAttribute("data-theme", "dark");
                    var iconL = document.querySelector(".theme-icon-light");
                    var iconD = document.querySelector(".theme-icon-dark");
                    if (iconL) iconL.style.display = "none";
                    if (iconD) iconD.style.display = "inline";
                } else {
                    document.documentElement.removeAttribute("data-theme");
                    document.body.removeAttribute("data-theme");
                    var iconL = document.querySelector(".theme-icon-light");
                    var iconD = document.querySelector(".theme-icon-dark");
                    if (iconL) iconL.style.display = "inline";
                    if (iconD) iconD.style.display = "none";
                }
            }

            // Lembrar usuário
            var loginInput = document.getElementById("login");
            var rememberCheckbox = document.getElementById("remember-me");
            var savedLogin = localStorage.getItem("contabuzu_saved_login");
            if (savedLogin && loginInput) {
                if (!loginInput.value) {
                    loginInput.value = savedLogin;
                }
                if (rememberCheckbox) rememberCheckbox.checked = true;
                var passwordInput = document.getElementById("password");
                if (passwordInput) passwordInput.focus();
            }

            // Toggle Visualizar Senha
            var btnTogglePass = document.getElementById("btn-toggle-password");
            var passInput = document.getElementById("password");
            if (btnTogglePass && passInput) {
                btnTogglePass.addEventListener("click", function() {
                    var isPassword = passInput.getAttribute("type") === "password";
                    passInput.setAttribute("type", isPassword ? "text" : "password");
                    var eyeOpen = btnTogglePass.querySelector(".eye-open-icon");
                    var eyeClosed = btnTogglePass.querySelector(".eye-closed-icon");
                    if (eyeOpen && eyeClosed) {
                        eyeOpen.style.display = isPassword ? "none" : "block";
                        eyeClosed.style.display = isPassword ? "block" : "none";
                    }
                });
            }

            // Detector de Caps Lock
            var capsWarning = document.getElementById("caps-lock-warning");
            if (passInput && capsWarning) {
                passInput.addEventListener("keyup", function(e) {
                    if (e.getModifierState && e.getModifierState("CapsLock")) {
                        capsWarning.style.display = "inline-flex";
                    } else {
                        capsWarning.style.display = "none";
                    }
                });
            }

            // Submit Form Loading State
            var form = document.getElementById("form-login");
            if (form) {
                form.addEventListener("submit", function() {
                    if (rememberCheckbox && rememberCheckbox.checked && loginInput) {
                        localStorage.setItem("contabuzu_saved_login", loginInput.value.trim());
                    } else if (rememberCheckbox && !rememberCheckbox.checked) {
                        localStorage.removeItem("contabuzu_saved_login");
                    }

                    var btn = document.getElementById("btn-entrar");
                    if (btn) {
                        btn.disabled = true;
                        var btnText = btn.querySelector(".btn-text");
                        var btnIcon = btn.querySelector(".btn-icon");
                        var btnLoader = btn.querySelector(".btn-loader");
                        if (btnText) btnText.style.display = "none";
                        if (btnIcon) btnIcon.style.display = "none";
                        if (btnLoader) btnLoader.style.display = "inline-flex";
                    }
                });
            }

            // Fechamento suave de alertas
            var alerts = document.querySelectorAll(".alert");
            alerts.forEach(function(alertEl) {
                setTimeout(function() {
                    alertEl.classList.add("fade-out");
                    setTimeout(function() {
                        alertEl.style.display = "none";
                    }, 500);
                }, 3500);
            });
        });
    </script>
</body>
</html>
