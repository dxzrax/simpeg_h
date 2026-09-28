/**
 * offline.js — Antrian Cek-in Offline (sisi halaman) untuk pegawai.php
 * ---------------------------------------------------------------------
 * Pasangan dari sw.js. File ini yang dipanggil langsung oleh form
 * cek-in di pegawai.php. Alur singkatnya:
 *
 *   1. Form disubmit lewat JS (bukan submit HTML biasa lagi).
 *   2. Coba kirim langsung via fetch() ke proses_cekin.php.
 *   3. Kalau BERHASIL -> arahkan ke halaman hasil seperti biasa.
 *   4. Kalau GAGAL karena benar-benar tidak ada koneksi (fetch
 *      melempar error jaringan) ATAU toggle "Simulasikan offline"
 *      sedang aktif -> simpan foto + data ke IndexedDB, tampilkan
 *      konfirmasi "tersimpan lokal", lalu minta Background Sync
 *      (kalau didukung) supaya sw.js mengirim ulang otomatis nanti.
 *   5. Sebagai jaring pengaman (Background Sync tidak didukung di
 *      semua browser, terutama Safari/iOS): file ini SENDIRI juga
 *      mencoba menyinkronkan setiap kali halaman dibuka atau event
 *      'online' terjadi.
 * ---------------------------------------------------------------------
 */

const OFFLINE_NAMA_DB = 'simpeg_h_offline';
const OFFLINE_VERSI_DB = 1;
const OFFLINE_NAMA_STORE = 'antrian_cekin';

function offlineBukaDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(OFFLINE_NAMA_DB, OFFLINE_VERSI_DB);
    req.onupgradeneeded = () => {
      if (!req.result.objectStoreNames.contains(OFFLINE_NAMA_STORE)) {
        req.result.createObjectStore(OFFLINE_NAMA_STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

/** Simpan satu submission cek-in ke IndexedDB (dipanggil saat gagal kirim langsung). */
async function offlineSimpanAntrian(fields, fotoBlob, actionUrl) {
  const db = await offlineBukaDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(OFFLINE_NAMA_STORE, 'readwrite');
    const req = tx.objectStore(OFFLINE_NAMA_STORE).add({
      fields, foto: fotoBlob, actionUrl, disimpanPada: new Date().toISOString(),
    });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function offlineHitungAntrian() {
  const db = await offlineBukaDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(OFFLINE_NAMA_STORE, 'readonly');
    const req = tx.objectStore(OFFLINE_NAMA_STORE).count();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function offlineAmbilSemuaAntrian() {
  const db = await offlineBukaDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(OFFLINE_NAMA_STORE, 'readonly');
    const req = tx.objectStore(OFFLINE_NAMA_STORE).getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function offlineHapusDariAntrian(id) {
  const db = await offlineBukaDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(OFFLINE_NAMA_STORE, 'readwrite');
    tx.objectStore(OFFLINE_NAMA_STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

/**
 * Jalur cadangan (dipanggil dari halaman, bukan dari sw.js) — dipakai
 * kalau Background Sync API tidak didukung browser, atau sebagai
 * percobaan tambahan saat halaman baru dibuka/kembali online.
 * Mengembalikan {berhasil, ditolak, sesiHabis} supaya UI bisa bereaksi.
 */
async function offlineSinkronkanSekarang() {
  const semuaItem = await offlineAmbilSemuaAntrian();
  let jumlahBerhasil = 0;
  let jumlahDitolak  = 0;
  let sesiHabis = false;

  // Kode penolakan server yang bersifat permanen — kirim ulang tidak akan
  // pernah berhasil karena masalahnya ada di data itu sendiri (bukan jaringan).
  const KODE_DITOLAK_PERMANEN = ['gagal_isi_foto', 'gagal_format_foto', 'gagal_ukuran_foto'];

  for (const item of semuaItem) {
    if (sesiHabis) break;

    // Lewati item yang sudah ditandai ditolak di siklus sebelumnya
    if (item.ditolak) continue;

    try {
      const fd = new FormData();
      for (const [k, v] of Object.entries(item.fields)) fd.append(k, v);
      fd.append('foto', item.foto, 'cekin_offline.jpg');

      const res = await fetch(item.actionUrl, { method: 'POST', body: fd });

      if (res.url && res.url.includes('error=csrf')) {
        sesiHabis = true; // semua item lain pasti gagal, hentikan
        break;
      }

      // Cek apakah server menolak karena masalah permanen pada berkas foto
      const urlPenolakan = KODE_DITOLAK_PERMANEN.find(k => res.url && res.url.includes('cekin=' + k));
      if (urlPenolakan) {
        // Tandai ditolak di IndexedDB — jangan hapus data pegawai
        await offlinaTandaiDitolak(item.id, urlPenolakan);
        jumlahDitolak++;
        continue;
      }

      const berhasil = res.url && (res.url.includes('cekin=berhasil') || res.url.includes('cekin=cekout_berhasil'));
      if (berhasil) {
        await offlineHapusDariAntrian(item.id);
        jumlahBerhasil++;
      }
      // Kegagalan lain (bukan sukses, bukan penolakan, bukan csrf) —
      // biarkan di antrian, coba lagi nanti (mungkin masalah sementara server)
    } catch (e) {
      break; // masih offline sungguhan — hentikan, coba lagi nanti
    }
  }
  return { berhasil: jumlahBerhasil, ditolak: jumlahDitolak, sesiHabis };
}

/** Tandai item antrean sebagai ditolak permanen — update di IndexedDB, jangan hapus. */
async function offlinaTandaiDitolak(id, alasan) {
  const db = await offlineBukaDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(OFFLINE_NAMA_STORE, 'readwrite');
    const store = tx.objectStore(OFFLINE_NAMA_STORE);
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



/** Daftarkan Service Worker + minta Background Sync (kalau didukung browser). */
async function offlineInitServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  try {
    const reg = await navigator.serviceWorker.register('sw.js');
    if ('sync' in reg) {
      // Didaftarkan setiap kali init supaya kalau ada antrian tertunda,
      // browser tetap tahu harus mencoba Background Sync saat online.
      try { await reg.sync.register('sync-cekin'); } catch (e) { /* browser tidak mendukung, jalur cadangan tetap ada */ }
    }
  } catch (e) {
    // Registrasi SW gagal (mis. dibuka lewat http:// bukan https:///localhost)
    // — offlineSinkronkanSekarang() di atas tetap jadi jalur cadangan.
    console.warn('SIMPEG-H: Service Worker gagal didaftarkan —', e);
  }
}
