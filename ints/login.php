<?php
/**
 * MBC SMS - Unified Login Page
 * Redirects based on user role (Manager, Agent, Client)
 */

require_once __DIR__ . '/api/config.php';

session_name(SESSION_NAME);
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    $redirectUrl = match ($_SESSION['role']) {
        'manager' => 'Manager_frontend/ints/manager/SMSDashboard.html',
        'agent' => 'Agent_frontend/ints/agent/SMSDashboard.html',
        'client' => 'client_frontend/ints/client/SMSDashboard.html',
        default => 'client_frontend/ints/client/SMSDashboard.html'
    };
    header("Location: $redirectUrl");
    exit;
}

// Generate captcha
$num1 = rand(0, 9);
$num2 = rand(0, 9);
$captchaAnswer = $num1 + $num2;
$sessionId = session_id();

// Store captcha in database
$db = Database::getInstance();
$db->query("DELETE FROM captcha_sessions WHERE session_id = ?", [$sessionId]);
$expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
$db->query(
    "INSERT INTO captcha_sessions (session_id, captcha_answer, expires_at) VALUES (?, ?, ?)",
    [$sessionId, $captchaAnswer, $expiresAt]
);

// Handle login form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captchaInput = (int)($_POST['capt'] ?? 0);
    
    // Verify captcha
    $stmt = $db->query(
        "SELECT captcha_answer FROM captcha_sessions WHERE session_id = ? AND expires_at > NOW()",
        [$sessionId]
    );
    $captcha = $stmt->fetch();
    
    if (!$captcha || $captchaInput !== (int)$captcha['captcha_answer']) {
        $error = 'Invalid captcha answer';
    } else {
        $db->query("DELETE FROM captcha_sessions WHERE session_id = ?", [$sessionId]);
        
        $hashedPassword = generateHash($password);
        $stmt = $db->query(
            "SELECT u.*, up.full_name, up.timezone FROM users u LEFT JOIN user_profiles up ON u.id = up.user_id WHERE u.username = ? AND u.password = ? AND u.status = 'active'",
            [$username, $hashedPassword]
        );
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['parent_id'] = $user['parent_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['timezone'] = $user['timezone'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            
            $db->query("UPDATE users SET last_login = NOW(), login_ip = ? WHERE id = ?", [$_SERVER['REMOTE_ADDR'], $user['id']]);
            
            logActivity($user['id'], 'login', 'User logged in');
            
            $redirectUrl = match ($user['role']) {
                'manager' => 'Manager_frontend/ints/manager/SMSDashboard.html',
                'agent' => 'Agent_frontend/ints/agent/SMSDashboard.html',
                'client' => 'client_frontend/ints/client/SMSDashboard.html',
                default => 'client_frontend/ints/client/SMSDashboard.html'
            };
            
            header("Location: $redirectUrl");
            exit;
        } else {
            $error = 'Invalid username or password';
            logActivity(null, 'login_failed', "Failed login attempt for username: $username");
        }
    }
    
    // Regenerate captcha after failed attempt
    $num1 = rand(0, 9);
    $num2 = rand(0, 9);
    $captchaAnswer = $num1 + $num2;
    $db->query("DELETE FROM captcha_sessions WHERE session_id = ?", [$sessionId]);
    $db->query(
        "INSERT INTO captcha_sessions (session_id, captcha_answer, expires_at) VALUES (?, ?, ?)",
        [$sessionId, $captchaAnswer, $expiresAt]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>MBC SMS | Login</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="./client_frontend/ints/ico/favicon.ico"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #04060f;
            overflow: hidden;
        }
        #rain-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
        .vignette {
            position: fixed; inset: 0; pointer-events: none;
            background: radial-gradient(circle at center, transparent 30%, rgba(2, 4, 10, 0.8) 120%);
            z-index: 1;
        }
        .login-container {
            position: relative; z-index: 10; width: 420px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: fadeInUp 0.7s ease both;
        }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .wave-container { position: absolute; bottom: 0; left: 0; width: 200%; height: 100px; pointer-events: none; z-index: 1; }
        .wave {
            position: absolute; bottom: 0; left: 0; width: 100%; height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='%23667eea' fill-opacity='0.15' d='M0,192L48,197.3C96,203,192,213,288,229.3C384,245,480,267,576,250.7C672,235,768,181,864,181.3C960,181,1056,235,1152,234.7C1248,235,1344,181,1392,154.7L1440,128L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z'%3E%3C/path%3E%3C/svg%3E");
            background-size: 50% 100%; background-repeat: repeat-x;
            animation: waveMove 12s linear infinite;
        }
        @keyframes waveMove { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
        .logo-circle {
            width: 80px; height: 80px; border-radius: 50%; background: #fff;
            display: flex; align-items: center; justify-content: center;
            margin: 40px auto 20px;
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.25);
            border: 3px solid #667eea;
        }
        .logo-circle img { width: 60px; height: 60px; object-fit: contain; }
        .login-header { text-align: center; margin-bottom: 30px; position: relative; z-index: 2; }
        .login-header h2 { font-size: 24px; color: #2d3436; font-weight: 700; margin-bottom: 8px; }
        .login-header p { color: #636e72; font-size: 14px; }
        .login-form { padding: 0 40px 40px; position: relative; z-index: 2; }
        .input-group { position: relative; margin-bottom: 24px; }
        .input-group input {
            width: 100%; padding: 16px 16px 16px 50px;
            background: #f1f4f9; border: 2px solid transparent;
            border-radius: 12px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: #2d3436;
            transition: all 0.3s ease;
        }
        .input-group input:focus {
            outline: none; border-color: #667eea; background: #fff;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
        }
        .input-group .icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; fill: #b2bec3; }
        .input-group input:focus ~ .icon { fill: #667eea; }
        .captcha-box {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.08) 100%);
            padding: 16px 20px; border-radius: 12px; margin-bottom: 24px;
            display: flex; align-items: center; justify-content: space-between; gap: 15px;
            border: 1px solid rgba(102, 126, 234, 0.15);
        }
        .captcha-text { font-size: 14px; color: #2d3436; font-weight: 500; }
        .captcha-text span { font-weight: 700; color: #667eea; }
        .captcha-box input {
            border: 2px solid #667eea; border-radius: 10px;
            padding: 10px 15px; width: 70px; text-align: center;
            font-size: 18px; font-weight: 700; outline: none; background: #fff;
        }
        .submit-btn {
            width: 100%; padding: 16px; border: none; border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; font-family: 'Inter', sans-serif;
            font-size: 16px; font-weight: 600; cursor: pointer;
            transition: all 0.3s ease;
        }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4); }
        .error-msg {
            background: #fee; color: #c00; padding: 12px 16px;
            border-radius: 8px; margin-bottom: 20px; font-size: 14px;
            border: 1px solid #fcc;
        }
        .info-box {
            background: #e8f4fd; color: #0066cc; padding: 12px 16px;
            border-radius: 8px; margin-bottom: 20px; font-size: 13px;
            border: 1px solid #b8daff;
        }
    </style>
</head>
<body>
    <canvas id="rain-canvas"></canvas>
    <div class="vignette"></div>
    
    <div class="login-container">
        <div class="wave-container">
            <div class="wave"></div>
        </div>
        
        <form method="post" class="login-form">
            <div class="logo-circle">
                <img src='./client_frontend/ints/ico/favicon.ico' alt='MBC SMS' />
            </div>
            
            <div class="login-header">
                <h2>Welcome Back</h2>
                <p>Sign in to your MBC SMS account</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="info-box">
                <strong>Default Credentials:</strong><br>
                Manager: <code>MBC SMS</code> / <code>MBC SMS</code>
            </div>
            
            <div class="input-group">
                <input type="text" name="username" placeholder="Username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                </svg>
            </div>
            
            <div class="input-group">
                <input type="password" name="password" placeholder="Password" required>
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M12,17A2,2 0 0,0 14,15C14,13.89 13.1,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z"/>
                </svg>
            </div>
            
            <div class="captcha-box">
                <div class="captcha-text">
                    Solve: <span><?php echo "$num1 + $num2 = ?"; ?></span>
                </div>
                <input type='number' name='capt' placeholder="?" required min="0" max="18">
            </div>
            
            <button type="submit" class="submit-btn">Login</button>
        </form>
    </div>
    
    <script>
        // Rain Animation
        (function() {
            const canvas = document.getElementById('rain-canvas');
            const ctx = canvas.getContext('2d');
            
            const layers = {
                back: { drops: [], max: 400, color: 'rgba(180, 200, 220, 0.12)', width: 1, speed: 8, length: 12 },
                mid: { drops: [], max: 250, color: 'rgba(180, 200, 220, 0.25)', width: 1.5, speed: 14, length: 20 },
                front: { drops: [], max: 80, color: 'rgba(200, 220, 255, 0.5)', width: 2, speed: 25, length: 35 }
            };
            
            function resize() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
                
                Object.keys(layers).forEach(key => {
                    const layer = layers[key];
                    layer.drops = [];
                    for (let i = 0; i < layer.max; i++) {
                        layer.drops.push({
                            x: Math.random() * canvas.width,
                            y: Math.random() * canvas.height - canvas.height,
                            length: layer.length * (0.8 + Math.random() * 0.4),
                            speed: layer.speed * (0.9 + Math.random() * 0.2)
                        });
                    }
                });
            }
            
            function draw() {
                ctx.fillStyle = 'rgba(4, 6, 15, 0.35)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                
                ctx.lineCap = 'round';
                
                Object.keys(layers).forEach(key => {
                    const layer = layers[key];
                    ctx.beginPath();
                    ctx.strokeStyle = layer.color;
                    ctx.lineWidth = layer.width;
                    
                    for (let i = 0; i < layer.drops.length; i++) {
                        const drop = layer.drops[i];
                        drop.y += drop.speed;
                        drop.x += 2 * (layer.width * 0.3);
                        
                        ctx.moveTo(drop.x, drop.y);
                        ctx.lineTo(drop.x - 2 * (layer.width * 0.3), drop.y - drop.length);
                        
                        if (drop.y > canvas.height) {
                            drop.y = -100 - Math.random() * 200;
                            drop.x = Math.random() * canvas.width * 1.5 - (canvas.width * 0.25);
                        }
                    }
                    ctx.stroke();
                });
                
                requestAnimationFrame(draw);
            }
            
            window.addEventListener('resize', resize);
            resize();
            draw();
        })();
    </script>
</body>
</html>
