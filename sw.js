/**
 * sw.js — Service Worker untuk SIMPEG-H (khusus pegawai.php)
 * ---------------------------------------------------------------------
 * Tanpa file ini, "mode offline" di pegawai.php cuma simulasi visual —
 * toggle yang mengubah tampilan tapi form tetap dikirim lewat POST
 * biasa yang GAGAL TOTAL kalau HP benar-benar tanpa sinyal. File ini
 * (bersama offline.js & manifest.json) membuatnya sungguhan:
 *
 * 1. Meng-cache aset statis (ikon, manifest) supaya bisa dipasang
 *    sebagai aplikasi (installable PWA).
 * 2. Menyediakan Background Sync ('sync-cekin') — begitu koneksi
 *    kembali, BROWSER (bukan halaman yang harus tetap terbuka) yang
 *    memanggil kode di bawah untuk mengirim ulang antrian cek-in yang
 *    tersimpan di IndexedDB.
 *
 * CATATAN PENTING — BATASAN Background Sync API:
 * Background Sync TIDAK didukung di Safari/iOS (per awal 2026). Untuk
 * perangkat itu, offline.js punya jalur cadangan: mencoba sinkron saat
 * event 'online' di jendela browser, dan saat pegawai membuka lagi
 * halaman/layar Absen. Jadi tetap tersinkron, hanya saja tidak
 * "otomatis di latar belakang" tanpa membuka aplikasi di iOS.
 * ---------------------------------------------------------------------
 */

// ── [POIN 2] Versi cache dinaikkan ke v2 karena ada aset baru (face-api)
const NAMA_CACHE_SHELL = 'simpeg-h-shell-v2';
const ASET_SHELL = [
  'manifest.json',
  'icons/icon-192.png',
  'icons/icon-512.png',
  // [POIN 2] face-api.js dan model lokal — di-cache supaya liveness
  // tetap berfungsi saat pegawai lapangan kehilangan sinyal
  'assets/face-api/face-api.min.js',
  'assets/face-api/liveness.js',
  'assets/face-api/models/tiny_face_detector_model-weights_manifest.json',
  'assets/face-api/models/tiny_face_detector_model-shard1',
  'assets/face-api/models/face_landmark_68_tiny_model-weights_manifest.json',
  'assets/face-api/models/face_landmark_68_tiny_model-shard1',
];

const NAMA_DB = 'simpeg_h_offline';
const VERSI_DB = 1;
const NAMA_STORE = 'antrian_cekin';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(NAMA_CACHE_SHELL).then((cache) => cache.addAll(ASET_SHELL)).catch(() => {
      // Kalau salah satu aset gagal di-cache (mis. ikon belum ada), jangan
      // gagalkan instalasi SW seluruhnya — cache shell itu sendiri bukan
      // bagian kritis dari mekanisme offline-first cek-in.
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Jangan pernah mencegat request POST (form cek-in) — itu sepenuhnya
// ditangani offline.js di halaman (coba fetch dulu, baru antre di
// IndexedDB kalau gagal). Service worker di sini hanya melayani GET
// untuk aset statis + halaman.
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const cocokAsetShell = ASET_SHELL.some((a) => req.url.endsWith(a));
  if (cocokAsetShell) {
    event.respondWith(caches.match(req).then((cached) => cached || fetch(req)));
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => new Response(
        `<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="font-family:'Plus Jakarta Sans',sans-serif;padding:40px 24px;text-align:center;color:#0F2438;background:#F4F6F8">
          <div style="font-size:40px;margin-bottom:12px">📶</div>
          <h2 style="margin:0 0 8px">Tidak Ada Koneksi</h2>
          <p style="color:#5B6B7A;font-size:14px;line-height:1.5">Halaman ini butuh koneksi untuk dimuat pertama kali. Cek-in yang sudah kamu isi sebelumnya (kalau ada) tetap tersimpan di perangkat dan akan otomatis terkirim begitu sinyal kembali.</p>
        </body></html>`,
        { headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
      ))
    );
  }
});

self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-cekin') {
    event.waitUntil(sinkronkanAntrianDariServiceWorker());
  }
});

/**
 * Dipanggil browser sendiri saat Background Sync terpicu. Membuka
 * IndexedDB langsung dari konteks Service Worker (tanpa perlu halaman
 * pegawai.php sedang terbuka) dan mengirim ulang tiap item antrian.
 */
async function sinkronkanAntrianDariServiceWorker() {
  const db = await bukaDB();
  const semuaItem = await new Promise((resolve, reject) => {
    const tx = db.transaction(NAMA_STORE, 'readonly');
    const req = tx.objectStore(NAMA_STORE).getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  // Penolakan permanen — foto bermasalah, kirim ulang tidak akan pernah berhasil
  const KODE_DITOLAK_PERMANEN = ['gagal_isi_foto', 'gagal_format_foto', 'gagal_ukuran_foto'];

  for (const item of semuaItem) {
    // Lewati item yang sudah ditandai ditolak
    if (item.ditolak) continue;

    try {
      const fd = new FormData();
      for (const [k, v] of Object.entries(item.fields)) fd.append(k, v);
      fd.append('foto', item.foto, 'cekin_offline.jpg');

      const res = await fetch(item.actionUrl, { method: 'POST', body: fd });

      if (res.url && res.url.includes('error=csrf')) {
        // Sesi login sudah kedaluwarsa selagi offline — SEMUA item di
        // antrian pasti akan gagal dengan alasan sama sampai pegawai
        // masuk ulang. Hentikan seluruh proses supaya tidak membanjiri
        // server dengan percobaan yang pasti gagal, biarkan item tetap
        // di antrian (tidak dihapus) untuk dicoba lagi setelah login ulang.
        await beriTahuHalaman({ tipe: 'sesi_habis' });
        return;
      }

      // Cek penolakan permanen berkas foto
      const kodeDitolak = KODE_DITOLAK_PERMANEN.find(k => res.url && res.url.includes('cekin=' + k));
      if (kodeDitolak) {
        await tandaiDitolakDiDB(db, item.id, kodeDitolak);
        await beriTahuHalaman({ tipe: 'ditolak', id: item.id, alasan: kodeDitolak });
        continue;
      }

      const berhasil = res.url && (
        res.url.includes('cekin=berhasil') || res.url.includes('cekin=cekout_berhasil')
      );

      if (berhasil) {
        await hapusDariAntrian(db, item.id);
        await beriTahuHalaman({ tipe: 'sinkron_berhasil', id: item.id });
      }
      // Kalau bukan sukses & bukan error csrf & bukan ditolak permanen
      // (mis. gagal validasi lain yang seharusnya sudah dicegah di sisi klien),
      // biarkan saja di antrian — jangan hapus data pegawai begitu saja.
    } catch (e) {
      // fetch melempar (masih benar-benar offline) — hentikan loop,
      // biarkan Background Sync API mencoba lagi otomatis nanti.
      throw e;
    }
  }
}

/** Tandai item di IndexedDB sebagai ditolak tanpa menghapusnya. */
function tandaiDitolakDiDB(db, id, alasan) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(NAMA_STORE, 'readwrite');
    const store = tx.objectStore(NAMA_STORE);
    const reqGet = store.get(id);
    reqGet.onsuccess = () => {
      const item = reqGet.result;
      if (item) {
        item.ditolak = true;
        item.alasan  = alasan;
        store.put(item);
      }
      tx.oncomplete = () => resolve();
      tx.onerror    = () => reject(tx.error);
    };
    reqGet.onerror = () => reject(reqGet.error);
  });
}



function hapusDariAntrian(db, id) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(NAMA_STORE, 'readwrite');
    tx.objectStore(NAMA_STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

async function beriTahuHalaman(pesan) {
  const semuaClient = await self.clients.matchAll({ includeUncontrolled: true });
  semuaClient.forEach((c) => c.postMessage(pesan));
}

function bukaDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(NAMA_DB, VERSI_DB);
    req.onupgradeneeded = () => {
      if (!req.result.objectStoreNames.contains(NAMA_STORE)) {
        req.result.createObjectStore(NAMA_STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
