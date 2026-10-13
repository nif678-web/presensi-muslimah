// ============================================
// Smart Attendance – Simulasi Frontend
// ============================================

const API_BASE = '../api';

// ---- State ----
let currentUser  = null;
let currentLat   = null;
let currentLng   = null;
let fotoBase64   = null;
let map          = null;
let markerUser   = null;
let markerKampus = null;
let circleRadius = null;
let jamInterval  = null;
let kameraStream = null;

// Koordinat kampus – diisi dari API settings
let KAMPUS_LAT = -6.2088;
let KAMPUS_LNG = 106.8456;
let MAX_RADIUS = 100;

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', async () => {
  // Ambil settings koordinat kampus dari server
  try {
    const res = await fetchJSON(`${API_BASE}/settings.php`);
    if (res.status === 'success' && res.data) {
      KAMPUS_LAT = parseFloat(res.data.kampus_lat?.value ?? KAMPUS_LAT);
      KAMPUS_LNG = parseFloat(res.data.kampus_lng?.value ?? KAMPUS_LNG);
      MAX_RADIUS = parseInt(res.data.max_radius?.value   ?? MAX_RADIUS);
    }
  } catch (_) { /* gunakan nilai default */ }

  const saved = sessionStorage.getItem('absensi_user');
  if (saved) {
    currentUser = JSON.parse(saved);
    showHome();
  } else {
    showPage('page-login');
  }

  updateJam();
  jamInterval = setInterval(updateJam, 1000);
});

// ============================================
// NAVIGASI
// ============================================
function showPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById(id).classList.add('active');
}

function showHome() {
  showPage('page-home');
  document.getElementById('user-name').textContent = currentUser.nama;
  document.getElementById('user-nim').textContent  = 'NIM: ' + currentUser.nim;
  switchTab('absensi');
  initMap();
  refreshLokasi();
}

function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach((b, i) => {
    b.classList.toggle('active',
      (i === 0 && tab === 'absensi') || (i === 1 && tab === 'riwayat')
    );
  });
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  if (tab === 'riwayat') loadRiwayat();
}

// ============================================
// JAM REAL-TIME
// ============================================
function updateJam() {
  const now = new Date();
  document.getElementById('tanggal-hari').textContent = now.toLocaleDateString('id-ID', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
  });
  document.getElementById('jam-sekarang').textContent = now.toLocaleTimeString('id-ID');
}

// ============================================
// LOGIN
// ============================================
function togglePassword() {
  const inp = document.getElementById('password');
  inp.type = inp.type === 'password' ? 'text' : 'password';
}

async function doLogin() {
  const nim      = document.getElementById('nim').value.trim();
  const password = document.getElementById('password').value.trim();
  const alertEl  = document.getElementById('login-alert');
  const btnText  = document.getElementById('login-btn-text');

  hideAlert(alertEl);

  if (!nim || !password) {
    showAlert(alertEl, 'NIM dan password wajib diisi', 'error');
    return;
  }

  btnText.innerHTML = '<span class="spinner"></span>Memproses…';
  document.querySelector('#page-login .btn-primary').disabled = true;

  try {
    const res = await postJSON(`${API_BASE}/login.php`, { nim, password });
    if (res.status === 'success') {
      currentUser = res.data;
      sessionStorage.setItem('absensi_user', JSON.stringify(currentUser));
      showHome();
    } else {
      showAlert(alertEl, res.message, 'error');
    }
  } catch (e) {
    showAlert(alertEl, 'Gagal terhubung ke server: ' + e.message, 'error');
  } finally {
    btnText.textContent = 'Masuk';
    document.querySelector('#page-login .btn-primary').disabled = false;
  }
}

function doLogout() {
  if (!confirm('Yakin ingin keluar?')) return;
  sessionStorage.removeItem('absensi_user');
  currentUser = null;
  fotoBase64  = null;
  currentLat  = null;
  currentLng  = null;
  tutupKamera();
  showPage('page-login');
  document.getElementById('nim').value      = '';
  document.getElementById('password').value = '';
}

// ============================================
// PETA (Leaflet)
// ============================================
function initMap() {
  if (map) return;

  map = L.map('map').setView([KAMPUS_LAT, KAMPUS_LNG], 16);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
  }).addTo(map);

  markerKampus = L.marker([KAMPUS_LAT, KAMPUS_LNG], {
    icon: L.divIcon({
      className: '',
      html: '<div style="font-size:28px;line-height:1">🏫</div>',
      iconAnchor: [14, 28]
    })
  }).addTo(map).bindPopup('<b>Lokasi Kampus</b>');

  circleRadius = L.circle([KAMPUS_LAT, KAMPUS_LNG], {
    radius: MAX_RADIUS,
    color: '#2563eb', fillColor: '#2563eb', fillOpacity: .1, weight: 2
  }).addTo(map);
}

function updateMapUser(lat, lng) {
  if (!map) return;
  if (markerUser) {
    markerUser.setLatLng([lat, lng]);
  } else {
    markerUser = L.marker([lat, lng], {
      icon: L.divIcon({
        className: '',
        html: '<div style="font-size:26px;line-height:1">📍</div>',
        iconAnchor: [13, 26]
      })
    }).addTo(map).bindPopup('<b>Posisi Anda</b>');
  }
  const bounds = L.latLngBounds([[lat, lng], [KAMPUS_LAT, KAMPUS_LNG]]);
  map.fitBounds(bounds, { padding: [40, 40] });
}

// ============================================
// GEOLOCATION
// ============================================
function refreshLokasi() {
  document.getElementById('koordinat-text').textContent    = 'Mendeteksi…';
  document.getElementById('jarak-text').textContent        = '–';
  document.getElementById('status-lokasi-text').textContent = '–';

  if (!navigator.geolocation) {
    document.getElementById('koordinat-text').textContent = 'Browser tidak mendukung GPS';
    return;
  }

  navigator.geolocation.getCurrentPosition(
    pos => {
      currentLat = pos.coords.latitude;
      currentLng = pos.coords.longitude;

      const jarak       = hitungJarak(currentLat, currentLng, KAMPUS_LAT, KAMPUS_LNG);
      const dalamRadius = jarak <= MAX_RADIUS;

      document.getElementById('koordinat-text').textContent =
        currentLat.toFixed(6) + ', ' + currentLng.toFixed(6);
      document.getElementById('jarak-text').textContent =
        Math.round(jarak) + ' m (maks ' + MAX_RADIUS + ' m)';
      document.getElementById('status-lokasi-text').innerHTML = dalamRadius
        ? '<span style="color:#16a34a">✅ Dalam radius kampus</span>'
        : '<span style="color:#dc2626">❌ Di luar radius kampus</span>';

      updateMapUser(currentLat, currentLng);
    },
    err => {
      const msg = err.code === 1 ? 'Izin lokasi ditolak. Aktifkan di pengaturan browser.'
                : err.code === 2 ? 'Lokasi tidak tersedia saat ini.'
                : 'Timeout mendapatkan lokasi.';
      document.getElementById('koordinat-text').textContent = msg;
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

function hitungJarak(lat1, lng1, lat2, lng2) {
  const R    = 6371000;
  const dLat = deg2rad(lat2 - lat1);
  const dLng = deg2rad(lng2 - lng1);
  const a    = Math.sin(dLat / 2) ** 2 +
               Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) *
               Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
function deg2rad(d) { return d * Math.PI / 180; }

// ============================================
// KAMERA
// ============================================
async function bukaKamera() {
  const alertEl = document.getElementById('absensi-alert');
  hideAlert(alertEl);

  // Cek apakah mediaDevices tersedia (butuh HTTPS atau localhost)
  const mediaOK = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

  if (!mediaOK) {
    // Fallback: buka file picker dengan capture agar di HP bisa langsung kamera
    showAlert(alertEl,
      'Kamera langsung tidak tersedia (butuh HTTPS). Gunakan tombol Upload Foto untuk memilih/mengambil foto.',
      'info'
    );
    // Langsung trigger file picker sebagai fallback
    const inp = document.getElementById('input-foto');
    inp.setAttribute('capture', 'user');
    inp.click();
    return;
  }

  try {
    kameraStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false
    });
    document.getElementById('video-stream').srcObject = kameraStream;
    document.getElementById('kamera-wrap').style.display  = 'block';
    document.getElementById('foto-actions').style.display = 'none';
    document.getElementById('kamera-hint').style.display  = 'none';
  } catch (e) {
    const msg = e.name === 'NotAllowedError'
      ? 'Izin kamera ditolak. Klik ikon kunci/info di address bar lalu izinkan Kamera, kemudian refresh halaman.'
      : e.name === 'NotFoundError'
      ? 'Kamera tidak ditemukan. Gunakan Upload Foto.'
      : e.name === 'NotReadableError'
      ? 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi lain lalu coba lagi.'
      : 'Gagal membuka kamera: ' + e.message;
    showAlert(alertEl, msg, 'error');
  }
}

function tutupKamera() {
  if (kameraStream) {
    kameraStream.getTracks().forEach(t => t.stop());
    kameraStream = null;
  }
  const kw = document.getElementById('kamera-wrap');
  const fa = document.getElementById('foto-actions');
  const kh = document.getElementById('kamera-hint');
  if (kw) kw.style.display = 'none';
  if (fa) fa.style.display = 'flex';
  if (kh) kh.style.display = 'block';
}

function ambilFoto() {
  const video  = document.getElementById('video-stream');
  const canvas = document.getElementById('canvas-foto');
  canvas.width  = video.videoWidth  || 640;
  canvas.height = video.videoHeight || 480;
  canvas.getContext('2d').drawImage(video, 0, 0);
  fotoBase64 = canvas.toDataURL('image/jpeg', 0.85);
  tampilkanPreview(fotoBase64);
  tutupKamera();
}

function uploadFotoFile(event) {
  const file = event.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    fotoBase64 = e.target.result;
    tampilkanPreview(fotoBase64);
  };
  reader.readAsDataURL(file);
}

function tampilkanPreview(src) {
  document.getElementById('foto-preview').src = src;
  document.getElementById('foto-preview-wrap').style.display = 'block';
  document.getElementById('foto-placeholder').style.display  = 'none';
}

function hapusFoto() {
  fotoBase64 = null;
  document.getElementById('foto-preview').src = '';
  document.getElementById('foto-preview-wrap').style.display = 'none';
  document.getElementById('foto-placeholder').style.display  = 'block';
  const inp = document.getElementById('input-foto');
  if (inp) inp.value = '';
}

// ============================================
// ABSENSI
// ============================================
async function doAbsensi() {
  const alertEl = document.getElementById('absensi-alert');
  const btn     = document.getElementById('btn-absen');
  hideAlert(alertEl);

  if (!currentLat || !currentLng) {
    showAlert(alertEl, 'Lokasi belum terdeteksi. Tekan "Perbarui Lokasi" terlebih dahulu.', 'error');
    return;
  }
  if (!fotoBase64) {
    showAlert(alertEl, 'Foto selfie wajib diambil sebelum absensi.', 'error');
    return;
  }

  btn.disabled  = true;
  btn.innerHTML = '<span class="spinner"></span>Memproses Absensi…';

  try {
    const res = await postJSON(`${API_BASE}/absensi.php`, {
      user_id:   currentUser.user_id,
      latitude:  currentLat,
      longitude: currentLng,
      foto:      fotoBase64
    });

    if (res.status === 'success') {
      const d       = res.data;
      const isHadir = d.status === 'hadir';
      const badge   = document.getElementById('absensi-status-badge');
      badge.textContent = isHadir ? '✅ Hadir' : '⚠️ Terlambat';
      badge.className   = 'badge ' + (isHadir ? 'badge-hadir' : 'badge-terlambat');
      showAlert(alertEl,
        `Absensi berhasil! Status: ${d.status.toUpperCase()} | Jarak: ${d.jarak} | Waktu: ${d.waktu}`,
        'success'
      );
      btn.innerHTML = '✅ Sudah Absen Hari Ini';
    } else {
      showAlert(alertEl, res.message, 'error');
      btn.disabled  = false;
      btn.innerHTML = '✅ Lakukan Absensi';
    }
  } catch (e) {
    showAlert(alertEl, 'Gagal terhubung ke server: ' + e.message, 'error');
    btn.disabled  = false;
    btn.innerHTML = '✅ Lakukan Absensi';
  }
}

// ============================================
// RIWAYAT
// ============================================
async function loadRiwayat() {
  const container = document.getElementById('riwayat-list');
  container.innerHTML =
    '<div class="empty-state"><span class="spinner" style="border-color:#94a3b8;border-top-color:#2563eb"></span> Memuat data…</div>';

  try {
    const res = await fetchJSON(`${API_BASE}/riwayat.php?user_id=${currentUser.user_id}`);
    if (res.status !== 'success' || !res.data || !res.data.length) {
      container.innerHTML = '<div class="empty-state">Belum ada riwayat absensi</div>';
      return;
    }

    container.innerHTML = res.data.map(item => {
      const tgl = new Date(item.waktu).toLocaleString('id-ID', {
        weekday: 'short', day: 'numeric', month: 'short',
        year: 'numeric', hour: '2-digit', minute: '2-digit'
      });
      const badgeCls = item.status === 'hadir' ? 'badge-hadir' : 'badge-terlambat';
      const fotoEl   = item.foto
        ? `<img class="riwayat-foto" src="../${item.foto}" alt="foto" onerror="this.style.display='none'">`
        : `<div class="riwayat-foto-ph">🙍</div>`;

      return `
        <div class="riwayat-item">
          ${fotoEl}
          <div class="riwayat-info">
            <div class="ri-tanggal">${tgl}</div>
            <div class="ri-status">
              <span class="badge ${badgeCls}">${item.status.toUpperCase()}</span>
            </div>
            <div class="ri-lokasi">
              📍 ${parseFloat(item.latitude).toFixed(5)}, ${parseFloat(item.longitude).toFixed(5)}
            </div>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    container.innerHTML =
      `<div class="empty-state" style="color:#dc2626">Gagal memuat: ${e.message}</div>`;
  }
}

// ============================================
// HELPERS
// ============================================
async function postJSON(url, data) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

async function fetchJSON(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

function showAlert(el, msg, type) {
  el.textContent = msg;
  el.className   = 'alert alert-' + type + ' show';
}

function hideAlert(el) {
  el.className   = 'alert';
  el.textContent = '';
}
