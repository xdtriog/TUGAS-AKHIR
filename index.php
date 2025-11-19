<?php
session_start();
require_once 'dbconnect.php';

// Jika sudah login, redirect ke dashboard sesuai tipe user
if (isset($_SESSION['user_id'])) {
    $id_users = $_SESSION['user_id'];
    $permision = $_SESSION['permision'];
    
    if ($permision == 1 || substr($id_users, 0, 4) == 'OWNR') {
        header("Location: pemilik/dashboard.php");
        exit();
    } elseif (substr($id_users, 0, 4) == 'TOKO') {
        header("Location: toko/dashboard.php");
        exit();
    } elseif (substr($id_users, 0, 4) == 'GDNG') {
        header("Location: gudang/dashboard.php");
        exit();
    }
}

$error = '';

// Proses login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($password)) {
        // Query untuk mencari user dengan status AKTIF
        $stmt = $conn->prepare("SELECT ID_USERS, NAMA, USERNAME, PASSWORD, STATUS, PERMISION FROM USERS WHERE USERNAME = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Cek apakah user aktif
            if ($user['STATUS'] != 'AKTIF') {
                $error = 'Akun Anda tidak aktif! Silakan hubungi administrator.';
            } elseif ($user['PASSWORD'] === $password) {
                // Verifikasi password (plain text untuk testing, bisa diubah ke password_verify jika sudah di-hash)
                // Set session
                $_SESSION['user_id'] = $user['ID_USERS'];
                $_SESSION['username'] = $user['USERNAME'];
                $_SESSION['nama'] = $user['NAMA'];
                $_SESSION['permision'] = $user['PERMISION'];
                
                // Redirect berdasarkan tipe user
                $id_users = $user['ID_USERS'];
                $permision = $user['PERMISION'];
                
                if ($permision == 1 || substr($id_users, 0, 4) == 'OWNR') {
                    // Pemilik/Owner
                    header("Location: pemilik/dashboard.php");
                    exit();
                } elseif (substr($id_users, 0, 4) == 'TOKO') {
                    // Staff Toko
                    header("Location: toko/dashboard.php");
                    exit();
                } elseif (substr($id_users, 0, 4) == 'GDNG') {
                    // Staff Gudang
                    header("Location: gudang/dashboard.php");
                    exit();
                }
            } else {
                $error = 'Username atau password salah!';
            }
        } else {
            $error = 'Username atau password salah!';
        }
        
        $stmt->close();
    } else {
        $error = 'Username dan password harus diisi!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Informasi Persediaan</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-container">
        <h1 class="login-title">Sistem Informasi Persediaan</h1>
        <p class="company-name">CV. KHARISMA WIJAYA ABADI KUSUMA</p>
        
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" required autofocus>
            </div>
            
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required>
            </div>
            
            <button type="submit" class="btn btn-login">Login</button>
        </form>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

