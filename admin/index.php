<?php
// ============================================
// Smart Attendance System - Admin Dashboard
// ============================================
require_once '../api/koneksi.php';

header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');

$conn = getConnection();

$registerMessage = '';
$registerError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_user'])) {
    $nama = trim($_POST['nama'] ?? '');
    $nim  = trim($_POST['nim'] ?? '');
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if ($nama === '' || $nim === '' || $pass === '' || $pass2 === '') {
        $registerError = 'Semua field wajib diisi.';
    } elseif (strlen($pass) < 6) {
        $registerError = 'Password minimal 6 karakter.';
    } elseif ($pass !== $pass2) {
        $registerError = 'Konfirmasi password tidak cocok.';
    } else {
        $stmtCheck = $conn->prepare('SELECT id FROM users WHERE nim = ?');
        $stmtCheck->bind_param('s', $nim);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();

        if ($resultCheck->num_rows > 0) {
            $registerError = 'NIM sudah terdaftar sebelumnya.';
        } else {
            $passwordHash = md5($pass);
            $stmtInsert = $conn->prepare('INSERT INTO users (nama, nim, password) VALUES (?, ?, ?)');
            $stmtInsert->bind_param('sss', $nama, $nim, $passwordHash);

            if ($stmtInsert->execute()) {
                $registerMessage = 'Registrasi berhasil. Mahasiswa ' . htmlspecialchars($nama) . ' telah ditambahkan.';
            } else {
                $registerError = 'Registrasi gagal. Silakan coba lagi.';
            }
        }
    }
}

// Statistik
$total     = $conn->query("SELECT COUNT(*) as c FROM absensi")->fetch_assoc()['c'];
$hadir     = $conn->query("SELECT COUNT(*) as c FROM absensi WHERE status='hadir'")->fetch_assoc()['c'];
$terlambat = $conn->query("SELECT COUNT(*) as c FROM absensi WHERE status='terlambat'")->fetch_assoc()['c'];
$mahasiswa = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];

// Absensi terbaru
$result  = $conn->query("
    SELECT a.id, a.latitude, a.longitude, a.foto, a.waktu, a.status,
           u.nama, u.nim
    FROM absensi a
    JOIN users u ON a.user_id = u.id
    ORDER BY a.waktu DESC
    LIMIT 50
");
$absensi = $result->fetch_all(MYSQLI_ASSOC);

// Mahasiswa yang sudah registrasi
$registeredUsersResult = $conn->query("
    SELECT id, nama, nim, created_at
    FROM users
    ORDER BY created_at DESC
");
$registeredUsers = $registeredUsersResult ? $registeredUsersResult->fetch_all(MYSQLI_ASSOC) : [];

// Settings
$resCfg = $conn->query("SELECT setting_key, setting_val, label FROM settings ORDER BY id");
$settings = [];
while ($r = $resCfg->fetch_assoc()) {
    $settings[$r['setting_key']] = ['value' => $r['setting_val'], 'label' => $r['label']];
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard – Smart Attendance</title>
  <!-- Leaflet untuk peta di panel settings -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    :root {
      --bg: #fff5f8;
      --bg-soft: #f4fbf7;
      --card: rgba(255, 255, 255, 0.92);
      --border: rgba(255, 182, 203, 0.35);
      --accent: #ff6b97;
      --accent-deep: #e04875;
      --pink-soft: #ffe6ef;
      --green: #2ecc71;
      --green-deep: #27ae60;
      --green-soft: #e8f8f0;
      --blue: #54a0ff;
      --red: #ff5252;
      --muted: #718093;
      --text: #2f3640;
      --shadow: 0 12px 30px rgba(255, 107, 151, 0.1);
      --radius: 20px;
    }

    body {
      font-family:'Plus Jakarta Sans',sans-serif;
      background:
        radial-gradient(circle at 10% 10%, rgba(255, 192, 213, 0.4), transparent 40%),
        radial-gradient(circle at 90% 90%, rgba(162, 241, 199, 0.3), transparent 40%),
        linear-gradient(135deg, var(--bg) 0%, var(--bg-soft) 100%);
      color:var(--text);
      min-height:100vh;
      position:relative;
      overflow-x:hidden;
    }

    /* Efek Kelopak Sakura Jatuh di Background */
    .sakura-petals {
      position: fixed;
      top: 0; left: 0; width: 100%; height: 100%;
      pointer-events: none;
      z-index: 0;
      overflow: hidden;
    }
    .sakura-petals span {
      position: absolute;
      display: block;
      background: linear-gradient(135deg, #ffb6c1, #ffc0cb);
      border-radius: 150% 0 150% 0;
      animation: fall linear infinite;
      opacity: 0.6;
    }
    @keyframes fall {
      0% { transform: translateY(-10vh) translateX(0) rotate(0deg); opacity: 0; }
      20% { opacity: 0.8; }
      100% { transform: translateY(110vh) translateX(50px) rotate(360deg); opacity: 0; }
    }

    header {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(12px);
      padding: 18px 32px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1.5px solid var(--border);
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 4px 20px rgba(255, 107, 151, 0.06);
    }
    .brand-title { 
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 1.3rem; 
      font-weight: 800; 
      color: var(--accent-deep);
    }
    .brand-logo {
      width: 40px;
      height: 40px;
      object-fit: contain;
      border-radius: 50%;
      background: #fff;
      padding: 3px;
      box-shadow: 0 2px 8px rgba(255, 107, 151, 0.2);
      border: 1.5px solid var(--border);
    }
    header .hdr-right { display: flex; align-items: center; gap: 12px; font-size: .88rem; color: var(--muted); font-weight: 600; }

    .nav-tabs {
      display: flex; background: rgba(255, 255, 255, 0.6);
      border-bottom: 1.5px solid var(--border);
      padding: 0 32px;
      position: relative; z-index: 1;
      backdrop-filter: blur(10px);
      gap: 8px;
    }
    .nav-tab {
      padding: 14px 24px; background: none; border: none; color: var(--muted);
      font-size: .92rem; font-weight: 700; cursor: pointer;
      border-bottom: 3px solid transparent; transition: all .25s ease;
      border-radius: 12px 12px 0 0; margin-top: 6px;
    }
    .nav-tab:hover { color: var(--accent); background: rgba(255, 107, 151, 0.04); }
    .nav-tab.active {
      color: var(--accent-deep); 
      border-bottom-color: var(--accent);
      background: #ffffff;
      box-shadow: 0 -4px 12px rgba(255, 107, 151, 0.08);
    }

    .container { padding: 28px 32px; max-width: 1250px; margin: 0 auto; position: relative; z-index: 1; }
    .tab-panel { display: none; animation: fadeIn .3s ease-in-out; }
    .tab-panel.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

    .stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 20px; margin-bottom: 28px; }
    .stat {
      background: var(--card);
      border: 1px solid var(--border); 
      padding: 24px; 
      border-radius: var(--radius); 
      text-align: center; 
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      transition: transform .2s ease;
    }
    .stat:hover { transform: translateY(-3px); }
    .stat .num { font-size: 2.5rem; font-weight: 800; letter-spacing: -1px; }
    .stat .lbl { font-size: .85rem; font-weight: 600; color: var(--muted); margin-top: 6px; text-transform: uppercase; letter-spacing: .5px; }
    .stat.orange .num { color: var(--accent-deep); }
    .stat.green .num { color: var(--green-deep); }
    .stat.red .num { color: var(--red); }
    .stat.blue .num { color: var(--blue); }

    .table-wrap {
      background: var(--card);
      border-radius: var(--radius); 
      overflow: hidden; 
      border: 1px solid var(--border); 
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      width: 100%; 
    }
    .table-title {
      padding: 18px 24px; font-weight: 700; color: var(--accent-deep); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
      background: linear-gradient(90deg, var(--pink-soft), var(--green-soft));
      font-size: 1.05rem;
    }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th {
      background: rgba(255, 182, 203, 0.15);
      padding: 14px 18px; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--text);
      border-bottom: 1.5px solid var(--border);
    }
    td { padding: 14px 18px; border-bottom: 1px solid rgba(255, 182, 203, 0.15); font-size: .9rem; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(255, 230, 239, 0.35); }
    
    .badge { display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 999px; font-size: .75rem; font-weight: 700; letter-spacing: .3px; }
    .badge.hadir { background: var(--green-soft); color: var(--green-deep); border: 1px solid rgba(46, 204, 113, 0.2); }
    .badge.terlambat { background: #ffebee; color: var(--red); border: 1px solid rgba(255, 82, 82, 0.2); }
    
    .foto-thumb { width: 44px; height: 44px; border-radius: 12px; object-fit: cover; border: 2px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .no-foto { width: 44px; height: 44px; background: var(--pink-soft); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; border: 2px solid var(--border); }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 20px; border-radius: 999px; border: none; font-size: .88rem; font-weight: 700; cursor: pointer; transition: all .2s ease; box-shadow: 0 6px 18px rgba(255, 107, 151, 0.15); }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(255, 107, 151, 0.25); }
    .btn:active { transform: translateY(0); }
    .btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }
    
    .btn-orange { background: linear-gradient(135deg, var(--accent), var(--accent-deep)); color: #fff; }
    .btn-blue { background: linear-gradient(135deg, #74b9ff, var(--blue)); color: #fff; box-shadow: 0 6px 18px rgba(84, 160, 255, 0.2); }
    .btn-green { background: linear-gradient(135deg, var(--green), var(--green-deep)); color: #fff; box-shadow: 0 6px 18px rgba(46, 204, 113, 0.2); }
    .btn-ghost { background: rgba(255, 255, 255, 0.8); color: var(--text); border: 1px solid var(--border); box-shadow: none; }
    .btn-ghost:hover { background: #fff; color: var(--accent); }

    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media(max-width: 850px){ .settings-grid{ grid-template-columns: 1fr; } }

    .settings-card {
      background: var(--card);
      border: 1px solid var(--border); 
      border-radius: var(--radius); 
      padding: 26px; 
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
    }
    .settings-card h3 { font-size: 1.1rem; color: var(--accent-deep); margin-bottom: 20px; display: flex; align-items: center; gap: 8px; font-weight: 700; }

    .registrasi-table-wrap {
      border: 1px solid var(--border);
      border-radius: 14px;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    }
    .registrasi-table { width: 100%; border-collapse: collapse; }
    .registrasi-table th { background: var(--pink-soft); color: var(--text); padding: 12px 16px; font-size: .78rem; text-transform: uppercase; }
    .registrasi-table td { padding: 12px 16px; border-bottom: 1px solid rgba(255, 182, 203, 0.15); font-size: .88rem; }
    .registrasi-table tbody tr:nth-child(even) { background: rgba(255, 245, 248, 0.4); }
    .registrasi-table tbody tr:hover { background: rgba(255, 230, 239, 0.4); }

    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-size: .82rem; font-weight: 700; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
    .form-group input {
      width: 100%; padding: 12px 16px;
      background: rgba(255, 255, 255, 0.9); 
      border: 1.5px solid var(--border);
      border-radius: 14px; color: var(--text); font-size: .92rem; outline: none;
      transition: all .2s ease;
    }
    .form-group input:focus {
      border-color: var(--accent);
      background: #fff;
      box-shadow: 0 0 0 4px rgba(255, 107, 151, 0.12);
    }

    .coord-row { display: flex; gap: 12px; }
    .coord-row .form-group { flex: 1; }

    #settings-map { height: 300px; border-radius: 14px; border: 1.5px solid var(--border); margin-bottom: 16px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }

    .alert { padding: 14px 18px; border-radius: 14px; font-size: .9rem; margin-bottom: 18px; border-left: 5px solid; display: none; font-weight: 600; }
    .alert.show { display: block; animation: fadeIn .3s ease; }
    .alert-success { background: var(--green-soft); color: var(--green-deep); border-color: var(--green); }
    .alert-error { background: #ffebee; color: var(--red); border-color: var(--red); }

    .spinner { display: inline-block; width: 16px; height: 16px; border: 2.5px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
    @keyframes spin { to{ transform: rotate(360deg); } }

    #radius-hint { font-size: .8rem; color: var(--muted); margin-top: 6px; font-weight: 500; }

    .gps-info { background: linear-gradient(135deg, var(--pink-soft), var(--green-soft)); border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; font-size: .85rem; margin-bottom: 16px; }
    .gps-info .gps-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed rgba(255, 182, 203, 0.3); }
    .gps-info .gps-row:last-child { border-bottom: none; }
    .gps-info .gps-row span:first-child { color: var(--muted); font-weight: 600; }
    .gps-info .gps-row span:last-child { font-weight: 700; color: var(--text); }

    @media (max-width: 768px) {
      .container { padding: 18px 14px; }
      header { padding: 14px 18px; flex-wrap: wrap; gap: 10px; }
      .brand-title { font-size: 1.1rem; }
      .nav-tabs { padding: 0 14px; overflow-x: auto; }
      .nav-tab { white-space: nowrap; padding: 12px 16px; }
      table { min-width: 650px; }
      .stat .num { font-size: 2rem; }
      .coord-row { flex-direction: column; gap: 0; }
    }
  </style>
</head>
<body>

<!-- Efek Kelopak Bunga Sakura Dekoratif -->
<div class="sakura-petals">
  <span style="left: 5%; width: 14px; height: 14px; animation-duration: 10s; animation-delay: 0s;"></span>
  <span style="left: 20%; width: 10px; height: 10px; animation-duration: 14s; animation-delay: 2s;"></span>
  <span style="left: 35%; width: 16px; height: 16px; animation-duration: 8s; animation-delay: 4s;"></span>
  <span style="left: 50%; width: 12px; height: 12px; animation-duration: 12s; animation-delay: 1s;"></span>
  <span style="left: 65%; width: 15px; height: 15px; animation-duration: 9s; animation-delay: 3s;"></span>
  <span style="left: 80%; width: 11px; height: 11px; animation-duration: 15s; animation-delay: 5s;"></span>
  <span style="left: 92%; width: 13px; height: 13px; animation-duration: 11s; animation-delay: 2.5s;"></span>
</div>

<header>
  <div class="brand-title">
    <!-- Path logo diarahkan ke folder frontend (asumsi file php berada di subfolder admin/) -->
    <img src="pic/logo.png" alt="Logo" class="brand-logo" onerror="this.onerror=null; this.src='pic.png';">
    Presensi Muslimah — Admin Dashboard
  </div>
  <div class="hdr-right">
    <span id="clock"><?= date('d M Y, H:i:s') ?></span>
    <button class="btn btn-ghost" onclick="location.reload()">🔄 Refresh</button>
    <a class="btn btn-ghost" href="../index.html" style="text-decoration:none;">🏠 Home</a>
  </div>
</header>

<!-- NAV TABS -->
<div class="nav-tabs">
  <button class="nav-tab active" data-tab="dashboard" onclick="switchTab('dashboard')">📋 Dashboard</button>
  <button class="nav-tab" data-tab="registrasi" onclick="switchTab('registrasi')">👤 Registrasi</button>
  <button class="nav-tab" data-tab="settings" onclick="switchTab('settings')">⚙️ Pengaturan Lokasi</button>
</div>

<!-- ===================== TAB DASHBOARD ===================== -->
<div id="tab-dashboard" class="tab-panel active">
  <div class="container">

    <div class="stats">
      <div class="stat orange"><div class="num"><?= $total ?></div><div class="lbl">Total Absensi</div></div>
      <div class="stat green"> <div class="num"><?= $hadir ?></div><div class="lbl">✓ Hadir</div></div>
      <div class="stat red">   <div class="num"><?= $terlambat ?></div><div class="lbl">⚠ Terlambat</div></div>
      <div class="stat blue">  <div class="num"><?= $mahasiswa ?></div><div class="lbl">👤 Mahasiswa</div></div>
    </div>

    <div class="table-wrap">
      <div class="table-title">
        <span>📋 Data Absensi Terbaru (50 data)</span>
        <button class="btn btn-orange" style="padding: 8px 16px; font-size: .8rem;" onclick="location.reload()">🔄 Refresh Data</button>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr><th>#</th><th>Foto</th><th>Nama</th><th>NIM</th><th>Waktu</th><th>Lokasi</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($absensi as $i => $row): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <?php if (!empty($row['foto'])): ?>
                  <img src="../<?= htmlspecialchars($row['foto']) ?>" class="foto-thumb" alt="foto">
                <?php else: ?>
                  <div class="no-foto">📷</div>
                <?php endif; ?>
              </td>
              <td><strong><?= htmlspecialchars($row['nama']) ?></strong></td>
              <td><?= htmlspecialchars($row['nim']) ?></td>
              <td><?= $row['waktu'] ?></td>
              <td style="font-size:.8rem;color:var(--muted)">
                <?= round($row['latitude'],5) ?>,<br><?= round($row['longitude'],5) ?>
              </td>
              <td><span class="badge <?= $row['status'] ?>"><?= strtoupper($row['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($absensi)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">Belum ada data absensi</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div><!-- /tab-dashboard -->

<!-- ===================== TAB REGISTRASI ===================== -->
<div id="tab-registrasi" class="tab-panel">
  <div class="container">
    <div class="settings-grid">
      <div class="settings-card" style="grid-column:1 / -1; max-width:100%; margin:0 auto;">
        <h3>🌸 Registrasi Mahasiswa Baru</h3>

        <?php if (!empty($registerError)): ?>
          <div class="alert alert-error show"><?= htmlspecialchars($registerError) ?></div>
        <?php elseif (!empty($registerMessage)): ?>
          <div class="alert alert-success show"><?= htmlspecialchars($registerMessage) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
          <input type="hidden" name="register_user" value="1">

          <div class="form-group">
            <label>Nama Lengkap</label>
            <input type="text" name="nama" placeholder="Masukkan nama lengkap mahasiswa" required>
          </div>

          <div class="form-group">
            <label>NIM</label>
            <input type="text" name="nim" placeholder="Contoh: 2021001" required>
          </div>

          <div class="coord-row">
            <div class="form-group">
              <label>Password</label>
              <input type="password" name="password" placeholder="Minimal 6 karakter" required>
            </div>
            <div class="form-group">
              <label>Konfirmasi Password</label>
              <input type="password" name="password_confirm" placeholder="Ulangi password" required>
            </div>
          </div>

          <button type="submit" class="btn btn-orange" style="width:100%; justify-content:center; margin-top: 6px;">
            ✨ Daftar Mahasiswa
          </button>
        </form>
      </div>

      <div class="settings-card" style="grid-column:1 / -1; max-width:100%; margin-top: 4px;">
        <h3>📋 Daftar Mahasiswa yang Sudah Registrasi</h3>

        <div class="registrasi-table-wrap">
          <table class="registrasi-table">
            <thead>
              <tr>
                <th style="width:60px;">#</th>
                <th>Nama</th>
                <th style="width:180px;">NIM</th>
                <th style="width:240px;">Waktu Registrasi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($registeredUsers as $i => $user): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($user['nama']) ?></strong></td>
                <td><?= htmlspecialchars($user['nim']) ?></td>
                <td style="color:var(--muted);"><?= htmlspecialchars($user['created_at']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($registeredUsers)): ?>
              <tr>
                <td colspan="4" style="text-align:center;padding:35px;color:var(--muted)">Belum ada mahasiswa yang terdaftar.</td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== TAB SETTINGS ===================== -->
<div id="tab-settings" class="tab-panel">
  <div class="container">

    <div id="settings-alert" class="alert"></div>

    <div class="settings-grid">

      <!-- KIRI: Form Pengaturan -->
      <div class="settings-card">
        <h3>⚙️ Konfigurasi Lokasi & Waktu</h3>

        <div class="coord-row">
          <div class="form-group">
            <label>Latitude Kampus</label>
            <input type="number" id="f-lat" step="0.000001" min="-90" max="90"
                   value="<?= htmlspecialchars($settings['kampus_lat']['value'] ?? '-6.2088') ?>"
                   oninput="updateMapFromForm()" />
          </div>
          <div class="form-group">
            <label>Longitude Kampus</label>
            <input type="number" id="f-lng" step="0.000001" min="-180" max="180"
                   value="<?= htmlspecialchars($settings['kampus_lng']['value'] ?? '106.8456') ?>"
                   oninput="updateMapFromForm()" />
          </div>
        </div>

        <div class="form-group">
          <label>Radius Maksimal (meter)</label>
          <input type="number" id="f-radius" min="10" max="5000" step="10"
                 value="<?= htmlspecialchars($settings['max_radius']['value'] ?? '100') ?>"
                 oninput="updateRadiusHint(); updateMapFromForm()" />
          <div id="radius-hint">Radius aktif: <?= htmlspecialchars($settings['max_radius']['value'] ?? '100') ?> meter</div>
        </div>

        <div class="coord-row">
          <div class="form-group">
            <label>Batas Jam Hadir</label>
            <input type="number" id="f-jam" min="0" max="23" step="1"
                   value="<?= htmlspecialchars($settings['batas_jam']['value'] ?? '8') ?>" />
          </div>
          <div class="form-group">
            <label>Batas Menit Hadir</label>
            <input type="number" id="f-menit" min="0" max="59" step="1"
                   value="<?= htmlspecialchars($settings['batas_menit']['value'] ?? '0') ?>" />
          </div>
        </div>
        <p style="font-size:.82rem;color:var(--muted);margin-bottom:20px;line-height: 1.5;">
          Absensi sebelum atau tepat jam <b id="batas-label" style="color:var(--accent-deep);"><?= ($settings['batas_jam']['value'] ?? '8') . ':' . str_pad($settings['batas_menit']['value'] ?? '0', 2, '0', STR_PAD_LEFT) ?></b> dihitung <b style="color:var(--green-deep);">Hadir</b>, setelahnya dihitung <b style="color:var(--red);">Terlambat</b>.
        </p>

        <button class="btn btn-green" id="btn-simpan" style="width: 100%; justify-content: center;" onclick="simpanSettings()">
          💾 Simpan Pengaturan
        </button>
      </div>

      <!-- KANAN: Peta -->
      <div class="settings-card">
        <h3>🗺️ Tentukan Lokasi di Peta</h3>

        <!-- Info GPS saat ini -->
        <div class="gps-info" id="gps-info">
          <div class="gps-row"><span>Status GPS</span><span id="gps-status">Mendeteksi…</span></div>
          <div class="gps-row"><span>Koordinat Anda</span><span id="gps-coord">–</span></div>
          <div class="gps-row"><span>Akurasi</span><span id="gps-akurasi">–</span></div>
        </div>

        <button class="btn btn-blue" style="width:100%;margin-bottom:14px;justify-content:center" onclick="gunakanLokasiSaatIni()">
          📍 Gunakan Lokasi Saya Sekarang
        </button>

        <div id="settings-map"></div>
        <p style="font-size:.8rem;color:var(--muted);margin-top:6px;line-height: 1.4;">
          💡 Klik pada peta untuk memindahkan titik kampus, atau tekan tombol di atas untuk menggunakan lokasi perangkat Anda saat ini.
        </p>
      </div>

    </div><!-- /settings-grid -->

  </div>
</div><!-- /tab-settings -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ============================================
// CLOCK
// ============================================
setInterval(() => {
  document.getElementById('clock').textContent = new Date().toLocaleString('id-ID');
}, 1000);

// ============================================
// TAB SWITCH
// ============================================
function switchTab(name) {
  document.querySelectorAll('.nav-tab').forEach(tab => {
    tab.classList.toggle('active', tab.dataset.tab === name);
  });
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  if (name === 'settings') initSettingsMap();
}

// ============================================
// SETTINGS MAP
// ============================================
let sMap = null, sMarker = null, sCircle = null;
let gpsLat = null, gpsLng = null;

function initSettingsMap() {
  if (sMap) return;

  const lat = parseFloat(document.getElementById('f-lat').value) || -6.2088;
  const lng = parseFloat(document.getElementById('f-lng').value) || 106.8456;
  const rad = parseInt(document.getElementById('f-radius').value) || 100;

  sMap = L.map('settings-map').setView([lat, lng], 16);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
  }).addTo(sMap);

  // Mengubah marker peta menggunakan logo kustom Anda dari folder unel/frontend
  sMarker = L.marker([lat, lng], { draggable: true,
    icon: L.divIcon({ className:'', html:'<div style="width:38px;height:38px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.25);border:2px solid #ff6b97;"><img src="../pic.png" style="width:28px;height:28px;object-fit:contain;" onerror="this.onerror=null; this.src=\'pic.png\';" /></div>', iconAnchor:[19,19] })
  }).addTo(sMap).bindPopup('<b>Lokasi Kampus</b><br>Geser untuk pindahkan');

  sCircle = L.circle([lat, lng], {
    radius: rad, color:'#ff6b97', fillColor:'#ff6b97', fillOpacity:.15, weight:2
  }).addTo(sMap);

  // Drag marker → update form
  sMarker.on('dragend', e => {
    const p = e.target.getLatLng();
    document.getElementById('f-lat').value = p.lat.toFixed(6);
    document.getElementById('f-lng').value = p.lng.toFixed(6);
    sCircle.setLatLng(p);
  });

  // Klik peta → pindahkan marker
  sMap.on('click', e => {
    sMarker.setLatLng(e.latlng);
    sCircle.setLatLng(e.latlng);
    document.getElementById('f-lat').value = e.latlng.lat.toFixed(6);
    document.getElementById('f-lng').value = e.latlng.lng.toFixed(6);
  });

  // Deteksi GPS
  detectGPS();
}

function updateMapFromForm() {
  if (!sMap) return;
  const lat = parseFloat(document.getElementById('f-lat').value);
  const lng = parseFloat(document.getElementById('f-lng').value);
  const rad = parseInt(document.getElementById('f-radius').value) || 100;
  if (isNaN(lat) || isNaN(lng)) return;
  sMarker.setLatLng([lat, lng]);
  sCircle.setLatLng([lat, lng]);
  sCircle.setRadius(rad);
  sMap.setView([lat, lng], sMap.getZoom());
  updateBatasLabel();
}

function updateRadiusHint() {
  const r = document.getElementById('f-radius').value;
  document.getElementById('radius-hint').textContent = 'Radius aktif: ' + r + ' meter';
  if (sCircle) sCircle.setRadius(parseInt(r) || 100);
}

function updateBatasLabel() {
  const j = document.getElementById('f-jam').value   || '8';
  const m = document.getElementById('f-menit').value || '0';
  document.getElementById('batas-label').textContent = j + ':' + String(m).padStart(2,'0');
}
document.getElementById('f-jam').addEventListener('input', updateBatasLabel);
document.getElementById('f-menit').addEventListener('input', updateBatasLabel);

// ============================================
// GPS – DETEKSI LOKASI SAAT INI
// ============================================
function detectGPS() {
  if (!navigator.geolocation) {
    document.getElementById('gps-status').textContent = 'Tidak didukung browser';
    return;
  }
  document.getElementById('gps-status').textContent = 'Mendeteksi…';
  navigator.geolocation.getCurrentPosition(
    pos => {
      gpsLat = pos.coords.latitude;
      gpsLng = pos.coords.longitude;
      document.getElementById('gps-status').textContent  = '✅ Berhasil';
      document.getElementById('gps-coord').textContent   = gpsLat.toFixed(6) + ', ' + gpsLng.toFixed(6);
      document.getElementById('gps-akurasi').textContent = '±' + Math.round(pos.coords.accuracy) + ' m';
    },
    err => {
      const msg = err.code === 1 ? 'Izin ditolak' : err.code === 2 ? 'Tidak tersedia' : 'Timeout';
      document.getElementById('gps-status').textContent = '❌ ' + msg;
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

function gunakanLokasiSaatIni() {
  if (gpsLat === null) {
    detectGPS();
    setTimeout(() => {
      if (gpsLat !== null) terapkanGPS();
      else alert('Lokasi belum terdeteksi. Pastikan izin lokasi diberikan di browser.');
    }, 4000);
    return;
  }
  terapkanGPS();
}

function terapkanGPS() {
  document.getElementById('f-lat').value = gpsLat.toFixed(6);
  document.getElementById('f-lng').value = gpsLng.toFixed(6);
  if (sMap) {
    sMarker.setLatLng([gpsLat, gpsLng]);
    sCircle.setLatLng([gpsLat, gpsLng]);
    sMap.setView([gpsLat, gpsLng], 17);
  }
}

// ============================================
// SIMPAN SETTINGS
// ============================================
async function simpanSettings() {
  const alertEl = document.getElementById('settings-alert');
  const btn     = document.getElementById('btn-simpan');
  alertEl.className = 'alert';

  const payload = {
    kampus_lat:  document.getElementById('f-lat').value,
    kampus_lng:  document.getElementById('f-lng').value,
    max_radius:  document.getElementById('f-radius').value,
    batas_jam:   document.getElementById('f-jam').value,
    batas_menit: document.getElementById('f-menit').value
  };

  if (!payload.kampus_lat || !payload.kampus_lng) {
    showAlert('error', 'Koordinat tidak boleh kosong'); return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Menyimpan…';

  try {
    const res = await fetch('../api/settings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (json.status === 'success') {
      showAlert('success', '✅ ' + json.message);
    } else {
      showAlert('error', '❌ ' + json.message);
    }
  } catch(e) {
    showAlert('error', '❌ Gagal terhubung ke server: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '💾 Simpan Pengaturan';
  }
}

function showAlert(type, msg) {
  const el = document.getElementById('settings-alert');
  el.textContent = msg;
  el.className = 'alert alert-' + type + ' show';
  el.scrollIntoView({ behavior:'smooth', block:'nearest' });
  setTimeout(() => el.classList.remove('show'), 5000);
}
</script>
</body>
</html>