      /*==============================================================*/
      /* DBMS name:      MySQL 5.0                                    */
      /* Dibuat ulang agar 100% kompatibel XAMPP (MySQL 5.7+/MariaDB) */
      /*==============================================================*/


SET FOREIGN_KEY_CHECKS = 0;
      CREATE DATABASE skripsi;
      USE skripsi;
DROP TABLE IF EXISTS BIAYA_OPERASIONAL;
DROP TABLE IF EXISTS DETAIL_NOTA_JUAL;
      DROP TABLE IF EXISTS DETAIL_TRANSFER_BARANG_BATCH;
DROP TABLE IF EXISTS DETAIL_TRANSFER_BARANG;
DROP TABLE IF EXISTS MASTER_BARANG;
DROP TABLE IF EXISTS MASTER_KATEGORI_BARANG;
DROP TABLE IF EXISTS MASTER_LOKASI;
DROP TABLE IF EXISTS MASTER_MEREK;
DROP TABLE IF EXISTS MASTER_SUPPLIER;
DROP TABLE IF EXISTS MASTER_TIPE_BIAYA_OPERASIONAL;
DROP TABLE IF EXISTS MUTASI_BARANG_RUSAK;
DROP TABLE IF EXISTS NOTA_JUAL;
      DROP TABLE IF EXISTS PERHITUNGAN_INTERVAL_POQ;
      DROP TABLE IF EXISTS PERHITUNGAN_KUANTITAS_POQ;
DROP TABLE IF EXISTS PESAN_BARANG;
DROP TABLE IF EXISTS STOCK;
DROP TABLE IF EXISTS STOCK_HISTORY;
DROP TABLE IF EXISTS STOCK_OPNAME;
DROP TABLE IF EXISTS TRANSFER_BARANG;
DROP TABLE IF EXISTS USERS;
SET FOREIGN_KEY_CHECKS = 1;

      /*==============================================================*/
      /* Table: BIAYA_OPERASIONAL                                     */
      /*==============================================================*/
      CREATE TABLE BIAYA_OPERASIONAL (
         ID_COST                      VARCHAR(16) NOT NULL,
         KD_LOKASI                    VARCHAR(8),
         KD_TIPE_BIAYA_OPERASIONAL    VARCHAR(16),
         JUMLAH_BIAYA_UANG            DECIMAL(20,2),
         PERIODE                      ENUM('HARIAN','BULANAN','TAHUNAN'),
         LAST_UPDATED                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (ID_COST)
      );

      /*==============================================================*/
      /* Table: DETAIL_NOTA_JUAL                                      */
      /*==============================================================*/
      CREATE TABLE DETAIL_NOTA_JUAL (
         ID_DNJB                      VARCHAR(16) NOT NULL,
         KD_BARANG                    VARCHAR(16),
         ID_NOTA_JUAL                 VARCHAR(16),
         JUMLAH_JUAL_BARANG           INT,
         HARGA_JUAL_BARANG            DECIMAL(20,2),
         TOTAL_JUAL_UANG              DECIMAL(20,2),
         HARGA_BELI_BARANG            DECIMAL(20,2),
         TOTAL_BELI_UANG              DECIMAL(20,2),
         PRIMARY KEY (ID_DNJB)
      );

/*==============================================================*/
/* Table: DETAIL_TRANSFER_BARANG                                */
/*==============================================================*/
CREATE TABLE DETAIL_TRANSFER_BARANG (
    ID_DETAIL_TRANSFER_BARANG    VARCHAR(16) NOT NULL,
    ID_TRANSFER_BARANG           VARCHAR(16),
    KD_BARANG                    VARCHAR(16),
    TOTAL_PESAN_TRANSFER_DUS     INT,
    TOTAL_KIRIM_DUS              INT,
    TOTAL_TIBA_DUS               INT,
    TOTAL_DITOLAK_DUS            INT,
    TOTAL_MASUK_DUS              INT,
    STATUS                       ENUM('DIPESAN', 'DIKIRIM', 'DIBATALKAN', 'SELESAI', 'TIDAK_DIKIRIM'),
    PRIMARY KEY (ID_DETAIL_TRANSFER_BARANG)
);

      /*==============================================================*/
      /* Table: DETAIL_TRANSFER_BARANG_BATCH                          */
      /*==============================================================*/
      CREATE TABLE DETAIL_TRANSFER_BARANG_BATCH (
         ID_DETAIL_TRANSFER_BARANG_BATCH VARCHAR(16) NOT NULL,
         ID_DETAIL_TRANSFER_BARANG    VARCHAR(16),
         ID_PESAN_BARANG              VARCHAR(16),
         JUMLAH_PESAN_TRANSFER_BATCH_DUS INT,
         JUMLAH_KIRIM_DUS             INT,
         JUMLAH_TIBA_DUS              INT,
         JUMLAH_DITOLAK_DUS           INT,
         JUMLAH_MASUK_DUS             INT,
         PRIMARY KEY (ID_DETAIL_TRANSFER_BARANG_BATCH)
      );

      /*==============================================================*/
      /* Table: MASTER_BARANG                                         */
      /*==============================================================*/
      CREATE TABLE MASTER_BARANG (
         KD_BARANG                    VARCHAR(16) NOT NULL,
         KD_KATEGORI_BARANG           VARCHAR(8),
         KD_SUPPLIER                  VARCHAR(8),
         KD_MEREK_BARANG              VARCHAR(8),
         NAMA_BARANG                  VARCHAR(256),
         BERAT                        INT,
         AVG_HARGA_BELI_PIECES        DECIMAL(20,2),
         HARGA_JUAL_BARANG_PIECES     DECIMAL(20,2),
         SATUAN_PERDUS                INT,
         GAMBAR_BARANG                VARCHAR(512),
         LAST_UPDATED                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         STATUS                       ENUM('AKTIF', 'TIDAK AKTIF'),
         PRIMARY KEY (KD_BARANG)
      );

      /*==============================================================*/
      /* Master tables                                                        */
      /*==============================================================*/
CREATE TABLE MASTER_KATEGORI_BARANG (
         KD_KATEGORI_BARANG   VARCHAR(8) NOT NULL,
         NAMA_KATEGORI        VARCHAR(256),
         STATUS               ENUM('AKTIF', 'TIDAK AKTIF'),
    PRIMARY KEY (KD_KATEGORI_BARANG)
      );

      CREATE TABLE MASTER_LOKASI (
         KD_LOKASI            VARCHAR(8) NOT NULL,
         NAMA_LOKASI          VARCHAR(256),
         TYPE_LOKASI          ENUM('gudang', 'toko'),
         ALAMAT_LOKASI        VARCHAR(256),
         MAX_STOCK_TOTAL      INT,
         SATUAN               ENUM('PIECES', 'DUS'),
         STATUS               ENUM('AKTIF', 'TIDAK AKTIF'),
         PRIMARY KEY (KD_LOKASI)
      );

CREATE TABLE MASTER_MEREK (
         KD_MEREK_BARANG      VARCHAR(8) NOT NULL,
         NAMA_MEREK           VARCHAR(256),
         STATUS               ENUM('AKTIF', 'TIDAK AKTIF'),
    PRIMARY KEY (KD_MEREK_BARANG)
      );

CREATE TABLE MASTER_SUPPLIER (
         KD_SUPPLIER          VARCHAR(8) NOT NULL,
         NAMA_SUPPLIER        VARCHAR(256),
         ALAMAT_SUPPLIER      VARCHAR(256),
         PIC_SUPPLIER         VARCHAR(256),
         NOTELP_SUPPLIER      VARCHAR(20),
         STATUS               ENUM('AKTIF', 'TIDAK AKTIF'),
    PRIMARY KEY (KD_SUPPLIER)
      );

      CREATE TABLE MASTER_TIPE_BIAYA_OPERASIONAL (
         KD_TIPE_BIAYA_OPERASIONAL VARCHAR(8) NOT NULL,
         NAMA_TIPE_BIAYA_OPERASIONAL VARCHAR(256),
         PRIMARY KEY (KD_TIPE_BIAYA_OPERASIONAL)
      );

      /*==============================================================*/
      /* Lain-lain                                                            */
      /*==============================================================*/
      CREATE TABLE MUTASI_BARANG_RUSAK (
         ID_MUTASI_BARANG_RUSAK VARCHAR(16) NOT NULL,
         KD_BARANG              VARCHAR(16),
         KD_LOKASI              VARCHAR(8),
         UPDATED_BY             VARCHAR(8),
         JUMLAH_MUTASI_DUS      INT,
         SATUAN_PERDUS          INT,
         TOTAL_BARANG_PIECES    INT,
         HARGA_BARANG_PIECES    DECIMAL(20,2),
         TOTAL_UANG             DECIMAL(20,2),
         REF                    VARCHAR(16),
         WAKTU_MUTASI           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (ID_MUTASI_BARANG_RUSAK)
      );

CREATE TABLE NOTA_JUAL (
         ID_NOTA_JUAL           VARCHAR(16) NOT NULL,
         ID_USERS               VARCHAR(8),
         KD_LOKASI              VARCHAR(8),
         WAKTU_NOTA             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         TOTAL_JUAL_BARANG      INT,
         SUB_TOTAL_JUAL         DECIMAL(20,2),
         PAJAK                  DECIMAL(20,2),
         GRAND_TOTAL            DECIMAL(20,2),
         SUB_TOTAL_BELI         DECIMAL(20,2),
         GROSS_PROFIT           DECIMAL(20,2),
         PRIMARY KEY (ID_NOTA_JUAL)
      );

      CREATE TABLE PERHITUNGAN_INTERVAL_POQ (
         ID_PERHITUNGAN_INTERVAL_POQ VARCHAR(16) NOT NULL,
         KD_LOKASI              VARCHAR(8),
         KD_BARANG              VARCHAR(16),
         DEMAND_RATE            INT,
         SETUP_COST             DECIMAL(20,2),
         HOLDING_COST           DECIMAL(20,2),
         INTERVAL_HARI          INT,
         WAKTU_PERHITUNGAN_INTERVAL_POQ TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (ID_PERHITUNGAN_INTERVAL_POQ)
      );

      CREATE TABLE PERHITUNGAN_KUANTITAS_POQ (
         ID_PERHITUNGAN_KUANTITAS_POQ VARCHAR(16) NOT NULL,
         ID_PERHITUNGAN_INTERVAL_POQ VARCHAR(16),
         KD_LOKASI              VARCHAR(8),
         KD_BARANG              VARCHAR(16),
         INTERVAL_HARI          INT,
         DEMAND_RATE            INT,
         LEAD_TIME              INT,
         STOCK_SEKARANG         INT,
         KUANTITAS_POQ          INT,
         WAKTU_PERHITUNGAN_KUANTITAS_POQ TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (ID_PERHITUNGAN_KUANTITAS_POQ)
      );

CREATE TABLE PESAN_BARANG (
         ID_PESAN_BARANG        VARCHAR(16) NOT NULL,
         KD_LOKASI              VARCHAR(8),
         KD_BARANG              VARCHAR(16),
         ID_PERHITUNGAN_KUANTITAS_POQ VARCHAR(16),
         KD_SUPPLIER            VARCHAR(8),
         HARGA_PESAN_BARANG_DUS DECIMAL(20,2),
         BIAYA_PENGIRIMAAN      DECIMAL(20,2),
         JUMLAH_PESAN_BARANG_DUS INT,
         JUMLAH_TIBA_DUS        INT,
         JUMLAH_DITOLAK_DUS     INT,
         TOTAL_MASUK_DUS        INT,
         SISA_STOCK_DUS         INT,
         TGL_EXPIRED            DATE,
         WAKTU_PESAN            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         WAKTU_ESTIMASI_SELESAI  TIMESTAMP NULL,
         WAKTU_SELESAI           TIMESTAMP NULL,
         STATUS                 ENUM('DIPESAN','DIKIRIM','DIBATALKAN','SELESAI'),
         PRIMARY KEY (ID_PESAN_BARANG)
      );

CREATE TABLE STOCK (
         KD_BARANG              VARCHAR(16) NOT NULL,
         KD_LOKASI              VARCHAR(8) NOT NULL,
         UPDATED_BY             VARCHAR(8),
         JUMLAH_BARANG          INT,
         JUMLAH_MIN_STOCK       INT,
         JUMLAH_MAX_STOCK       INT,
         SATUAN                 ENUM('PIECES', 'DUS'),
         LAST_UPDATED           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (KD_BARANG, KD_LOKASI)
      );

CREATE TABLE STOCK_HISTORY (
         ID_HISTORY_STOCK       VARCHAR(16) NOT NULL,
         KD_BARANG              VARCHAR(16),
         KD_LOKASI              VARCHAR(8),
         UPDATED_BY             VARCHAR(8),
         JUMLAH_AWAL            INT,
         JUMLAH_PERUBAHAN       INT,
         JUMLAH_AKHIR           INT,
         TIPE_PERUBAHAN         ENUM('PEMESANAN', 'TRANSFER', 'OPNAME', 'RUSAK', 'PENJUALAN'),
         REF                    VARCHAR(16),
         SATUAN                 ENUM('PIECES', 'DUS'),
         WAKTU_CHANGE           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (ID_HISTORY_STOCK)
      );

CREATE TABLE STOCK_OPNAME (
         ID_OPNAME              VARCHAR(16) NOT NULL,
    KD_BARANG              VARCHAR(16),
         KD_LOKASI              VARCHAR(8),
         ID_USERS               VARCHAR(8),
         JUMLAH_SEBENARNYA      INT,
         JUMLAH_SISTEM          INT,
         SELISIH                INT,
         SATUAN                 ENUM('PIECES', 'DUS'),
         SATUAN_PERDUS          INT,
         TOTAL_BARANG_PIECES    INT,
         HARGA_BARANG_PIECES    DECIMAL(20,2),
         TOTAL_UANG             DECIMAL(20,2),
         WAKTU_OPNAME           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         REF_BATCH              VARCHAR(16),
         PRIMARY KEY (ID_OPNAME)
      );

      CREATE TABLE TRANSFER_BARANG (
         ID_TRANSFER_BARANG     VARCHAR(16) NOT NULL,
         ID_USERS_PENERIMA      VARCHAR(8),
         ID_USERS_PENGIRIM      VARCHAR(8),
         KD_LOKASI_ASAL         VARCHAR(8),
         KD_LOKASI_TUJUAN       VARCHAR(8),
         WAKTU_PESAN_TRANSFER   TIMESTAMP NULL,
         WAKTU_KIRIM_TRANSFER   TIMESTAMP NULL,
         WAKTU_SELESAI_TRANSFER    TIMESTAMP NULL,
         STATUS                 ENUM('DIPESAN', 'DIKIRIM', 'DIBATALKAN', 'SELESAI'),
         PRIMARY KEY (ID_TRANSFER_BARANG)
      );

      CREATE TABLE USERS (
         ID_USERS               VARCHAR(8) NOT NULL,
         KD_LOKASI              VARCHAR(8),
         NAMA                   VARCHAR(256),
         USERNAME               VARCHAR(256),
         PASSWORD               VARCHAR(256),
         STATUS                 ENUM('AKTIF', 'TIDAK AKTIF'),
         PRIMARY KEY (ID_USERS)
      );

      /*==============================================================*/
      /* Foreign Keys (harus di paling akhir biar tidak error urutan) */
      /*==============================================================*/

      ALTER TABLE BIAYA_OPERASIONAL ADD CONSTRAINT FK_BIAYA_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE BIAYA_OPERASIONAL ADD CONSTRAINT FK_BIAYA_TIPE FOREIGN KEY (KD_TIPE_BIAYA_OPERASIONAL) REFERENCES MASTER_TIPE_BIAYA_OPERASIONAL (KD_TIPE_BIAYA_OPERASIONAL);

      ALTER TABLE DETAIL_NOTA_JUAL ADD CONSTRAINT FK_DNJ_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
      ALTER TABLE DETAIL_NOTA_JUAL ADD CONSTRAINT FK_DNJ_NOTA FOREIGN KEY (ID_NOTA_JUAL) REFERENCES NOTA_JUAL (ID_NOTA_JUAL);

      ALTER TABLE DETAIL_TRANSFER_BARANG ADD CONSTRAINT FK_DT_TRANSFER FOREIGN KEY (ID_TRANSFER_BARANG) REFERENCES TRANSFER_BARANG (ID_TRANSFER_BARANG);
      ALTER TABLE DETAIL_TRANSFER_BARANG ADD CONSTRAINT FK_DT_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);

      ALTER TABLE DETAIL_TRANSFER_BARANG_BATCH ADD CONSTRAINT FK_DTB_DETAIL FOREIGN KEY (ID_DETAIL_TRANSFER_BARANG) REFERENCES DETAIL_TRANSFER_BARANG (ID_DETAIL_TRANSFER_BARANG);
      ALTER TABLE DETAIL_TRANSFER_BARANG_BATCH ADD CONSTRAINT FK_DTB_PESAN FOREIGN KEY (ID_PESAN_BARANG) REFERENCES PESAN_BARANG (ID_PESAN_BARANG);

      ALTER TABLE MASTER_BARANG ADD CONSTRAINT FK_BARANG_MEREK FOREIGN KEY (KD_MEREK_BARANG) REFERENCES MASTER_MEREK (KD_MEREK_BARANG);
      ALTER TABLE MASTER_BARANG ADD CONSTRAINT FK_BARANG_KATEGORI FOREIGN KEY (KD_KATEGORI_BARANG) REFERENCES MASTER_KATEGORI_BARANG (KD_KATEGORI_BARANG);
      ALTER TABLE MASTER_BARANG ADD CONSTRAINT FK_BARANG_SUPPLIER FOREIGN KEY (KD_SUPPLIER) REFERENCES MASTER_SUPPLIER (KD_SUPPLIER);

      ALTER TABLE MUTASI_BARANG_RUSAK ADD CONSTRAINT FK_MUTASI_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
      ALTER TABLE MUTASI_BARANG_RUSAK ADD CONSTRAINT FK_MUTASI_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE MUTASI_BARANG_RUSAK ADD CONSTRAINT FK_MUTASI_USER FOREIGN KEY (UPDATED_BY) REFERENCES USERS (ID_USERS);

      ALTER TABLE NOTA_JUAL ADD CONSTRAINT FK_NOTA_USER FOREIGN KEY (ID_USERS) REFERENCES USERS (ID_USERS);
      ALTER TABLE NOTA_JUAL ADD CONSTRAINT FK_NOTA_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);

      ALTER TABLE PERHITUNGAN_INTERVAL_POQ ADD CONSTRAINT FK_INTERVAL_POQ_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE PERHITUNGAN_INTERVAL_POQ ADD CONSTRAINT FK_INTERVAL_POQ_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);

      ALTER TABLE PERHITUNGAN_KUANTITAS_POQ ADD CONSTRAINT FK_KUANTITAS_POQ_INTERVAL FOREIGN KEY (ID_PERHITUNGAN_INTERVAL_POQ) REFERENCES PERHITUNGAN_INTERVAL_POQ (ID_PERHITUNGAN_INTERVAL_POQ);
      ALTER TABLE PERHITUNGAN_KUANTITAS_POQ ADD CONSTRAINT FK_KUANTITAS_POQ_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE PERHITUNGAN_KUANTITAS_POQ ADD CONSTRAINT FK_KUANTITAS_POQ_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);

      ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
      ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_POQ FOREIGN KEY (ID_PERHITUNGAN_KUANTITAS_POQ) REFERENCES PERHITUNGAN_KUANTITAS_POQ (ID_PERHITUNGAN_KUANTITAS_POQ);
      ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_SUPPLIER FOREIGN KEY (KD_SUPPLIER) REFERENCES MASTER_SUPPLIER (KD_SUPPLIER);

      ALTER TABLE STOCK ADD CONSTRAINT FK_STOCK_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
      ALTER TABLE STOCK ADD CONSTRAINT FK_STOCK_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE STOCK ADD CONSTRAINT FK_STOCK_USER FOREIGN KEY (UPDATED_BY) REFERENCES USERS (ID_USERS);

      ALTER TABLE STOCK_HISTORY ADD CONSTRAINT FK_HIST_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG) ON DELETE RESTRICT ON UPDATE RESTRICT;
      ALTER TABLE STOCK_HISTORY ADD CONSTRAINT FK_HIST_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI) ON DELETE RESTRICT ON UPDATE RESTRICT;
      ALTER TABLE STOCK_HISTORY ADD CONSTRAINT FK_HIST_USER FOREIGN KEY (UPDATED_BY) REFERENCES USERS (ID_USERS) ON DELETE RESTRICT ON UPDATE RESTRICT;

      ALTER TABLE STOCK_OPNAME ADD CONSTRAINT FK_OPNAME_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
      ALTER TABLE STOCK_OPNAME ADD CONSTRAINT FK_OPNAME_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE STOCK_OPNAME ADD CONSTRAINT FK_OPNAME_USER FOREIGN KEY (ID_USERS) REFERENCES USERS (ID_USERS);

      ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRF_PENGIRIM FOREIGN KEY (ID_USERS_PENGIRIM) REFERENCES USERS (ID_USERS);
      ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRF_PENERIMA FOREIGN KEY (ID_USERS_PENERIMA) REFERENCES USERS (ID_USERS);
      ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRF_ASAL FOREIGN KEY (KD_LOKASI_ASAL) REFERENCES MASTER_LOKASI (KD_LOKASI);
      ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRF_TUJUAN FOREIGN KEY (KD_LOKASI_TUJUAN) REFERENCES MASTER_LOKASI (KD_LOKASI);

      ALTER TABLE USERS ADD CONSTRAINT FK_USERS_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);

-- Dummy Data
INSERT INTO `master_kategori_barang` (`KD_KATEGORI_BARANG`, `NAMA_KATEGORI`, `STATUS`) VALUES
('a0Umyjh3', 'Fried Chicken', 'AKTIF'),
('a1dPMAPr', 'Luncheon', 'AKTIF'),
('cx7xRUzL', 'Nugget', 'AKTIF'),
('kaJWciKi', 'Fillet', 'AKTIF'),
('N9YZGVmr', 'Sosis', 'AKTIF'),
('Zh0gHW3h', 'Karaage', 'AKTIF'),
('ZichPP7X', 'Fries', 'AKTIF'),
('ZjpbKhZ9', 'Dimsum', 'AKTIF'),
('ZmaHEKYZ', 'Seafood', 'AKTIF'),
('Zp6yAXpZ', 'Balls', 'AKTIF'),
('Zqt2j3bN', 'Stick', 'AKTIF'),
('ZtJtetxh', 'Ayam Potong', 'AKTIF'),
('Zvjt6a4L', 'Bakso', 'AKTIF'),
('Zy05nfEF', 'Wings', 'AKTIF');

INSERT INTO `master_merek` (`KD_MEREK_BARANG`, `NAMA_MEREK`, `STATUS`) VALUES
('a7bhyL91', 'Fiesta', 'AKTIF'),
('a9stS5pc', 'So Good', 'AKTIF'),
('aBasx0Ad', 'Champ', 'AKTIF'),
('aDTThcsC', 'Belfoods', 'AKTIF');

INSERT INTO `master_lokasi` (`KD_LOKASI`, `NAMA_LOKASI`, `TYPE_LOKASI`, `ALAMAT_LOKASI`, `MAX_STOCK_TOTAL`, `SATUAN`, `STATUS`) VALUES
('GDNGj825', 'Gudang Pusat Blora', 'gudang', 'Jl. Gudang Blora No 1', 2000, 'DUS', 'AKTIF'),
('TOKOeLig', 'Toko Blora', 'toko', 'Jl. Toko Blora No 1', 5000, 'PIECES', 'AKTIF'),
('TOKOjtqG', 'Toko Rembang', 'toko', 'Jl. Toko Rembang No 1', 5000, 'PIECES', 'AKTIF'),
('TOKOk3CZ', 'Toko Cepu', 'toko', 'Jl. Toko Cepu No 1', 5000, 'PIECES', 'AKTIF');

INSERT INTO `master_supplier` (`KD_SUPPLIER`, `NAMA_SUPPLIER`, `ALAMAT_SUPPLIER`, `PIC_SUPPLIER`, `NOTELP_SUPPLIER`, `STATUS`) VALUES
('ZaC8tpzz', 'PT. Champ Resto Indonesia', 'Jl. Industri Raya No. 10, Bekasi', 'Siti Rahayu', '083456789012', 'AKTIF'),
('ZdGE3cqy', 'PT. Belfoods Indonesia', 'Jl. Raya Jakarta-Bogor Km 47, Cibinong', 'Rina Setiawan', '084567890123', 'AKTIF'),
('ZQHNayEq', 'PT. Fiesta Food Indonesia', 'Jl. Raya Bogor Km 29, Cimanggis, Depok', 'Budi Santoso', '081234567890', 'AKTIF'),
('ZVJwQzHe', 'PT. So Good Food Manufacturing', 'Jl. Raya Serang Km 13.8, Cikupa, Tangerang', 'Andi Wijaya', '082345678901', 'AKTIF');

INSERT INTO `users` (`ID_USERS`, `KD_LOKASI`, `NAMA`, `USERNAME`, `PASSWORD`, `STATUS`) VALUES
('GDNGj825', 'GDNGj825', 'Yudi', 'gudang1', 'password', 'AKTIF'),
('OWNR3f8x', NULL, 'Kevin', 'owner', 'password', 'AKTIF'),
('TOKOeLig', 'TOKOeLig', 'Hendro', 'toko1', 'password', 'AKTIF'),
('TOKOrvYu', 'TOKOjtqG', 'Farhan', 'toko3', 'password', 'AKTIF'),
('TOKOu8EU', 'TOKOk3CZ', 'Amal', 'toko2', 'password', 'AKTIF');

INSERT INTO `master_barang` (`KD_BARANG`, `KD_KATEGORI_BARANG`, `KD_SUPPLIER`, `KD_MEREK_BARANG`, `NAMA_BARANG`, `BERAT`, `AVG_HARGA_BELI_PIECES`, `HARGA_JUAL_BARANG_PIECES`, `SATUAN_PERDUS`, `GAMBAR_BARANG`, `LAST_UPDATED`, `STATUS`) VALUES
('0qiaT6r5h6tx1vG5', 'Zvjt6a4L', NULL, 'a9stS5pc', 'So Good Bakso Bakso Meatballs 500gr', 500, NULL, NULL, 12, 'assets/images/barang/0qiaT6r5h6tx1vG5_1765869078.webp', '2025-12-16 07:11:18', 'AKTIF'),
('3SMMLSAekpQ7YHBm', 'kaJWciKi', NULL, 'aDTThcsC', 'Belfoods Fillet Royal Golden Fillet 250gr', 250, NULL, NULL, 24, 'assets/images/barang/3SMMLSAekpQ7YHBm_1765869895.webp', '2025-12-16 07:24:55', 'AKTIF'),
('4eJNEAcagseDtEgc', 'ZtJtetxh', NULL, 'a9stS5pc', 'So Good Ayam Potong Ayam Potong Paha & Dada 1000gr', 1000, NULL, NULL, 10, 'assets/images/barang/4eJNEAcagseDtEgc_1765869024.png', '2025-12-16 07:10:24', 'AKTIF'),
('5FkHT4Mzbx4W5kQE', 'Zh0gHW3h', NULL, 'a7bhyL91', 'Fiesta Karaage Chicken Karaage 500gr', 500, NULL, NULL, 12, 'assets/images/barang/5FkHT4Mzbx4W5kQE_1765867937.webp', '2025-12-16 06:52:17', 'AKTIF'),
('5trjzMBdbhei4ZJY', 'cx7xRUzL', NULL, 'a7bhyL91', 'Fiesta Nugget Shrimp Nugget (Bubble) 500gr', 500, NULL, NULL, 12, 'assets/images/barang/5trjzMBdbhei4ZJY_1765867886.webp', '2025-12-16 06:51:26', 'AKTIF'),
('6HYQiMz32fakk4k7', 'ZichPP7X', NULL, 'aDTThcsC', 'Belfoods Fries Frozen French Fries 500gr', 500, NULL, NULL, 12, 'assets/images/barang/6HYQiMz32fakk4k7_1765870592.jpg', '2025-12-16 07:36:32', 'AKTIF'),
('7hvrUALJk9fW53Uw', 'Zqt2j3bN', NULL, 'aDTThcsC', 'Belfoods Stick Favorite Chicken Stick 500gr', 500, NULL, NULL, 12, 'assets/images/barang/7hvrUALJk9fW53Uw_1765869751.jpg', '2025-12-16 07:22:31', 'AKTIF'),
('7KHAaDZe1ncb5PxZ', 'cx7xRUzL', NULL, 'aDTThcsC', 'Belfoods Nugget Nugget Coin 500gr', 500, NULL, 0.00, 12, 'assets/images/barang/7KHAaDZe1ncb5PxZ_1765872633.jpeg', '2025-12-16 08:10:33', 'AKTIF'),
('8e0exsihhJ5vyw4R', 'N9YZGVmr', NULL, 'a9stS5pc', 'So Good Sosis Chicken Sausage 500gr', 500, NULL, NULL, 12, 'assets/images/barang/8e0exsihhJ5vyw4R_1765869121.webp', '2025-12-16 07:12:01', 'AKTIF'),
('abPg4AgMfBYJQFbY', 'Zqt2j3bN', NULL, 'a9stS5pc', 'So Good Stick Hot Crispy Chicken Stick 500gr', 500, NULL, NULL, 12, 'assets/images/barang/abPg4AgMfBYJQFbY_1765868655.jpeg', '2025-12-16 07:04:15', 'AKTIF'),
('BmQgQAmDbUifCNkp', 'cx7xRUzL', NULL, 'a7bhyL91', 'Fiesta Nugget Chicken Nugget 500gr', 500, NULL, NULL, 12, 'assets/images/barang/BmQgQAmDbUifCNkp_1765867837.webp', '2025-12-16 06:50:37', 'AKTIF'),
('bSqgiWf3hbrwBtn8', 'N9YZGVmr', NULL, 'aBasx0Ad', 'Champ Sosis Sosis Ayam 1000gr', 1000, NULL, NULL, 10, 'assets/images/barang/bSqgiWf3hbrwBtn8_1765869189.jpg', '2025-12-16 07:13:09', 'AKTIF'),
('cJR1HX6NcEBkvQa9', 'ZichPP7X', NULL, 'a7bhyL91', 'Fiesta Fries French Fries Shoestring 500gr', 500, NULL, NULL, 12, 'assets/images/barang/cJR1HX6NcEBkvQa9_1765868002.jpg', '2025-12-16 06:53:22', 'AKTIF'),
('d7d7HhD0d8c4B1aT', 'ZmaHEKYZ', NULL, 'a7bhyL91', 'Fiesta Seafood Ebi Fry 250gr', 250, NULL, NULL, 24, 'assets/images/barang/d7d7HhD0d8c4B1aT_1765868202.webp', '2025-12-16 06:56:42', 'AKTIF'),
('dQVmWBpb4rAtaFyX', 'a1dPMAPr', NULL, 'aDTThcsC', 'Belfoods Luncheon Luncheon Ayam 500gr', 500, NULL, NULL, 12, 'assets/images/barang/dQVmWBpb4rAtaFyX_1765871070.jpeg', '2025-12-16 07:44:30', 'AKTIF'),
('EipfpEa23tx29rxA', 'a0Umyjh3', NULL, 'aDTThcsC', 'Belfoods Fried Chicken Crispy Fried Chicken 500gr', 500, NULL, NULL, 12, 'assets/images/barang/EipfpEa23tx29rxA_1765870860.png', '2025-12-16 07:41:00', 'AKTIF'),
('fEuFwvHHggEZGAuM', 'Zqt2j3bN', NULL, 'a9stS5pc', 'So Good Stick Crispy Chicken Stick 500gr', 500, NULL, NULL, 12, 'assets/images/barang/fEuFwvHHggEZGAuM_1765868985.png', '2025-12-16 07:09:45', 'AKTIF'),
('hrzWDaube4kT8Hra', 'Zp6yAXpZ', NULL, 'a7bhyL91', 'Fiesta Balls Chicken Balls 500gr', 500, NULL, NULL, 12, 'assets/images/barang/hrzWDaube4kT8Hra_1765868408.jpeg', '2025-12-16 07:00:08', 'AKTIF'),
('i4nFqC8PgEZEHQYc', 'N9YZGVmr', NULL, 'a9stS5pc', 'So Good Sosis Sosis Jepang Hot 375gr', 375, NULL, NULL, 12, 'assets/images/barang/i4nFqC8PgEZEHQYc_1765868887.png', '2025-12-16 07:08:07', 'AKTIF'),
('iMq19Qq7ccrK2mMe', 'ZjpbKhZ9', NULL, 'a7bhyL91', 'Fiesta Dimsum Siomay Ayam 180gr', 180, NULL, NULL, 24, 'assets/images/barang/iMq19Qq7ccrK2mMe_1765868089.webp', '2025-12-16 06:54:49', 'AKTIF'),
('jgdV5NWf41WKmiHb', 'a0Umyjh3', NULL, 'aDTThcsC', 'Belfoods Fried Chicken Ayam Goreng Pedas 500gr', 500, NULL, NULL, 12, 'assets/images/barang/jgdV5NWf41WKmiHb_1765870888.jpg', '2025-12-16 07:41:28', 'AKTIF'),
('MAcpqAYAjxhGyScJ', 'cx7xRUzL', NULL, 'aDTThcsC', 'Belfoods Nugget Chicken Nugget Crunchy 500gr', 500, NULL, NULL, 12, 'assets/images/barang/MAcpqAYAjxhGyScJ_1765869706.png', '2025-12-16 07:21:46', 'AKTIF'),
('mh560H4Z4RpSFTnz', 'Zvjt6a4L', NULL, 'aDTThcsC', 'Belfoods Bakso Bakso Ayam Medium 500gr', 500, NULL, NULL, 12, 'assets/images/barang/mh560H4Z4RpSFTnz_1765870981.jpeg', '2025-12-16 07:43:01', 'AKTIF'),
('MpHjcrYfdsSZCZk3', 'ZmaHEKYZ', NULL, 'a7bhyL91', 'Fiesta Seafood Odeng 500gr', 500, NULL, NULL, 12, 'assets/images/barang/MpHjcrYfdsSZCZk3_1765868361.jpg', '2025-12-16 06:59:21', 'AKTIF'),
('n1F0Bxwc3arcFkcV', 'cx7xRUzL', NULL, 'aDTThcsC', 'Belfoods Nugget Chicken Nugget Ceria 500gr', 500, NULL, NULL, 12, 'assets/images/barang/n1F0Bxwc3arcFkcV_1765870795.jpg', '2025-12-16 07:39:55', 'AKTIF'),
('NmffHrvs1NmdsNtk', 'N9YZGVmr', NULL, 'aDTThcsC', 'Belfoods Sosis Uenak Sosis Bakar 500gr', 500, NULL, NULL, 12, 'assets/images/barang/NmffHrvs1NmdsNtk_1765870307.jpg', '2025-12-16 07:31:47', 'AKTIF'),
('PiBLjDVZctBYPYhp', 'ZjpbKhZ9', NULL, 'a7bhyL91', 'Fiesta Dimsum Shrimp Shumai 200gr', 200, NULL, NULL, 24, 'assets/images/barang/PiBLjDVZctBYPYhp_1765868143.webp', '2025-12-16 06:55:43', 'AKTIF'),
('qASpqzyP3FQ7TKJg', 'cx7xRUzL', NULL, 'aDTThcsC', 'Belfoods Nugget Chicken Nugget Safari 500gr', 500, NULL, NULL, 12, 'assets/images/barang/qASpqzyP3FQ7TKJg_1765870721.png', '2025-12-16 07:38:41', 'AKTIF'),
('sxDvWY0uiU1zu8uz', 'cx7xRUzL', NULL, 'aBasx0Ad', 'Champ Nugget Nugget Coin 200gr', 200, NULL, NULL, 24, 'assets/images/barang/sxDvWY0uiU1zu8uz_1765869380.jpg', '2025-12-16 07:16:20', 'AKTIF'),
('t3DiGNqjiBjUns42', 'Zp6yAXpZ', NULL, 'aBasx0Ad', 'Champ Balls Beef Ball 500gr', 500, NULL, NULL, 10, 'assets/images/barang/t3DiGNqjiBjUns42_1765869318.webp', '2025-12-16 07:15:18', 'AKTIF'),
('U3Avc19Uiip05fih', 'cx7xRUzL', NULL, 'aBasx0Ad', 'Champ Nugget Nugget Ayam 500gr', 500, NULL, NULL, 10, 'assets/images/barang/U3Avc19Uiip05fih_1765869436.jpg', '2025-12-16 07:17:16', 'AKTIF'),
('uafjPLDC2SFFw7Rk', 'Zy05nfEF', NULL, 'aDTThcsC', 'Belfoods Wings Spicy Wings 500gr', 500, NULL, NULL, 12, 'assets/images/barang/uafjPLDC2SFFw7Rk_1765870541.png', '2025-12-16 07:35:41', 'AKTIF'),
('uTb95XLLdJdfEJ8u', 'ZmaHEKYZ', NULL, 'a7bhyL91', 'Fiesta Seafood Dory Stick 500gr', 500, NULL, 0.00, 12, 'assets/images/barang/uTb95XLLdJdfEJ8u_1765868240.webp', '2025-12-16 06:57:31', 'AKTIF'),
('VHUkNBy8eqEXPVS1', 'cx7xRUzL', NULL, 'a9stS5pc', 'So Good Nugget Hot Crispy Chicken Nugget 500gr', 500, NULL, NULL, 12, 'assets/images/barang/VHUkNBy8eqEXPVS1_1765868574.jpeg', '2025-12-16 07:02:54', 'AKTIF'),
('vLwTPWXVitRFFFLJ', 'ZjpbKhZ9', NULL, 'aBasx0Ad', 'Champ Dimsum Siomay Ayam 180gr', 180, NULL, NULL, 24, 'assets/images/barang/vLwTPWXVitRFFFLJ_1765869469.webp', '2025-12-16 07:17:49', 'AKTIF'),
('x2RqyPEi640Bx5c0', 'Zvjt6a4L', NULL, 'aDTThcsC', 'Belfoods Bakso Bakso Ayam 500gr', 500, NULL, NULL, 12, 'assets/images/barang/x2RqyPEi640Bx5c0_1765870015.jpeg', '2025-12-16 07:26:55', 'AKTIF'),
('Xd7uGwF2hrKTbD4P', 'N9YZGVmr', NULL, 'aBasx0Ad', 'Champ Sosis Sosis Ayam 375gr', 375, NULL, 0.00, 24, 'assets/images/barang/Xd7uGwF2hrKTbD4P_1765869240.webp', '2025-12-16 07:14:38', 'AKTIF'),
('XMA9bdbvm8CXVsYU', 'Zh0gHW3h', NULL, 'aDTThcsC', 'Belfoods Karaage Royal Crispy Karaage 500gr', 500, NULL, NULL, 12, 'assets/images/barang/XMA9bdbvm8CXVsYU_1765869967.png', '2025-12-16 07:26:07', 'AKTIF'),
('XzN8unV1kQRnjha4', 'cx7xRUzL', NULL, 'aDTThcsC', 'Belfoods Nugget Royal Nugget S 250gr', 250, NULL, NULL, 24, 'assets/images/barang/XzN8unV1kQRnjha4_1765869808.jpg', '2025-12-16 07:23:28', 'AKTIF'),
('ZwcXmVPafgipxtmm', 'N9YZGVmr', NULL, 'a9stS5pc', 'So Good Sosis Sosis Jepang Keju Saus Pedas 375gr', 375, NULL, NULL, 12, 'assets/images/barang/ZwcXmVPafgipxtmm_1765868766.png', '2025-12-16 07:06:06', 'AKTIF');

INSERT INTO `stock` (`KD_BARANG`, `KD_LOKASI`, `UPDATED_BY`, `JUMLAH_BARANG`, `JUMLAH_MIN_STOCK`, `JUMLAH_MAX_STOCK`, `SATUAN`, `LAST_UPDATED`) VALUES
('0qiaT6r5h6tx1vG5', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:11:18'),
('0qiaT6r5h6tx1vG5', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:11:18'),
('0qiaT6r5h6tx1vG5', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:11:18'),
('0qiaT6r5h6tx1vG5', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:11:18'),
('3SMMLSAekpQ7YHBm', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:24:55'),
('3SMMLSAekpQ7YHBm', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:24:55'),
('3SMMLSAekpQ7YHBm', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:24:55'),
('3SMMLSAekpQ7YHBm', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:24:55'),
('4eJNEAcagseDtEgc', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:10:24'),
('4eJNEAcagseDtEgc', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:10:24'),
('4eJNEAcagseDtEgc', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:10:24'),
('4eJNEAcagseDtEgc', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:10:24'),
('5FkHT4Mzbx4W5kQE', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:52:17'),
('5FkHT4Mzbx4W5kQE', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:52:17'),
('5FkHT4Mzbx4W5kQE', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:52:17'),
('5FkHT4Mzbx4W5kQE', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:52:17'),
('5trjzMBdbhei4ZJY', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:51:26'),
('5trjzMBdbhei4ZJY', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:51:26'),
('5trjzMBdbhei4ZJY', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:51:26'),
('5trjzMBdbhei4ZJY', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:51:26'),
('6HYQiMz32fakk4k7', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:36:32'),
('6HYQiMz32fakk4k7', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:36:32'),
('6HYQiMz32fakk4k7', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:36:32'),
('6HYQiMz32fakk4k7', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:36:32'),
('7hvrUALJk9fW53Uw', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:22:31'),
('7hvrUALJk9fW53Uw', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:22:31'),
('7hvrUALJk9fW53Uw', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:22:31'),
('7hvrUALJk9fW53Uw', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:22:31'),
('7KHAaDZe1ncb5PxZ', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:33:18'),
('7KHAaDZe1ncb5PxZ', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:33:18'),
('7KHAaDZe1ncb5PxZ', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:33:18'),
('7KHAaDZe1ncb5PxZ', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:33:18'),
('8e0exsihhJ5vyw4R', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:12:01'),
('8e0exsihhJ5vyw4R', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:12:01'),
('8e0exsihhJ5vyw4R', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:12:01'),
('8e0exsihhJ5vyw4R', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:12:01'),
('abPg4AgMfBYJQFbY', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:04:15'),
('abPg4AgMfBYJQFbY', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:04:15'),
('abPg4AgMfBYJQFbY', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:04:15'),
('abPg4AgMfBYJQFbY', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:04:15'),
('BmQgQAmDbUifCNkp', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:50:37'),
('BmQgQAmDbUifCNkp', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:50:37'),
('BmQgQAmDbUifCNkp', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:50:37'),
('BmQgQAmDbUifCNkp', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:50:37'),
('bSqgiWf3hbrwBtn8', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:13:09'),
('bSqgiWf3hbrwBtn8', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:13:09'),
('bSqgiWf3hbrwBtn8', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:13:09'),
('bSqgiWf3hbrwBtn8', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:13:09'),
('cJR1HX6NcEBkvQa9', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:53:22'),
('cJR1HX6NcEBkvQa9', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:53:22'),
('cJR1HX6NcEBkvQa9', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:53:22'),
('cJR1HX6NcEBkvQa9', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:53:22'),
('d7d7HhD0d8c4B1aT', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:56:42'),
('d7d7HhD0d8c4B1aT', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:56:42'),
('d7d7HhD0d8c4B1aT', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:56:42'),
('d7d7HhD0d8c4B1aT', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:56:42'),
('dQVmWBpb4rAtaFyX', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:44:30'),
('dQVmWBpb4rAtaFyX', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:44:30'),
('dQVmWBpb4rAtaFyX', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:44:30'),
('dQVmWBpb4rAtaFyX', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:44:30'),
('EipfpEa23tx29rxA', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:41:00'),
('EipfpEa23tx29rxA', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:41:00'),
('EipfpEa23tx29rxA', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:41:00'),
('EipfpEa23tx29rxA', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:41:00'),
('fEuFwvHHggEZGAuM', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:09:45'),
('fEuFwvHHggEZGAuM', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:09:45'),
('fEuFwvHHggEZGAuM', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:09:45'),
('fEuFwvHHggEZGAuM', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:09:45'),
('hrzWDaube4kT8Hra', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:00:08'),
('hrzWDaube4kT8Hra', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:00:08'),
('hrzWDaube4kT8Hra', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:00:08'),
('hrzWDaube4kT8Hra', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:00:08'),
('i4nFqC8PgEZEHQYc', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:08:07'),
('i4nFqC8PgEZEHQYc', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:08:07'),
('i4nFqC8PgEZEHQYc', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:08:07'),
('i4nFqC8PgEZEHQYc', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:08:07'),
('iMq19Qq7ccrK2mMe', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:54:49'),
('iMq19Qq7ccrK2mMe', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:54:49'),
('iMq19Qq7ccrK2mMe', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:54:49'),
('iMq19Qq7ccrK2mMe', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:54:49'),
('jgdV5NWf41WKmiHb', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:41:28'),
('jgdV5NWf41WKmiHb', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:41:28'),
('jgdV5NWf41WKmiHb', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:41:28'),
('jgdV5NWf41WKmiHb', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:41:28'),
('MAcpqAYAjxhGyScJ', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:21:46'),
('MAcpqAYAjxhGyScJ', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:21:46'),
('MAcpqAYAjxhGyScJ', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:21:46'),
('MAcpqAYAjxhGyScJ', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:21:46'),
('mh560H4Z4RpSFTnz', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:43:01'),
('mh560H4Z4RpSFTnz', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:43:01'),
('mh560H4Z4RpSFTnz', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:43:01'),
('mh560H4Z4RpSFTnz', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:43:01'),
('MpHjcrYfdsSZCZk3', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:59:21'),
('MpHjcrYfdsSZCZk3', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:59:21'),
('MpHjcrYfdsSZCZk3', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:59:21'),
('MpHjcrYfdsSZCZk3', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:59:21'),
('n1F0Bxwc3arcFkcV', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:39:55'),
('n1F0Bxwc3arcFkcV', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:39:55'),
('n1F0Bxwc3arcFkcV', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:39:55'),
('n1F0Bxwc3arcFkcV', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:39:55'),
('NmffHrvs1NmdsNtk', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:31:47'),
('NmffHrvs1NmdsNtk', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:31:47'),
('NmffHrvs1NmdsNtk', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:31:47'),
('NmffHrvs1NmdsNtk', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:31:47'),
('PiBLjDVZctBYPYhp', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:55:43'),
('PiBLjDVZctBYPYhp', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:55:43'),
('PiBLjDVZctBYPYhp', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:55:43'),
('PiBLjDVZctBYPYhp', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:55:43'),
('qASpqzyP3FQ7TKJg', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:38:41'),
('qASpqzyP3FQ7TKJg', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:38:41'),
('qASpqzyP3FQ7TKJg', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:38:41'),
('qASpqzyP3FQ7TKJg', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:38:41'),
('sxDvWY0uiU1zu8uz', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:16:20'),
('sxDvWY0uiU1zu8uz', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:16:20'),
('sxDvWY0uiU1zu8uz', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:16:20'),
('sxDvWY0uiU1zu8uz', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:16:20'),
('t3DiGNqjiBjUns42', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:15:18'),
('t3DiGNqjiBjUns42', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:15:18'),
('t3DiGNqjiBjUns42', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:15:18'),
('t3DiGNqjiBjUns42', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:15:18'),
('U3Avc19Uiip05fih', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:17:16'),
('U3Avc19Uiip05fih', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:17:16'),
('U3Avc19Uiip05fih', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:17:16'),
('U3Avc19Uiip05fih', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:17:16'),
('uafjPLDC2SFFw7Rk', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:35:41'),
('uafjPLDC2SFFw7Rk', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:35:41'),
('uafjPLDC2SFFw7Rk', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:35:41'),
('uafjPLDC2SFFw7Rk', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:35:41'),
('uTb95XLLdJdfEJ8u', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 06:57:20'),
('uTb95XLLdJdfEJ8u', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:57:20'),
('uTb95XLLdJdfEJ8u', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:57:20'),
('uTb95XLLdJdfEJ8u', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 06:57:20'),
('VHUkNBy8eqEXPVS1', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:02:54'),
('VHUkNBy8eqEXPVS1', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:02:54'),
('VHUkNBy8eqEXPVS1', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:02:54'),
('VHUkNBy8eqEXPVS1', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:02:54'),
('vLwTPWXVitRFFFLJ', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:17:49'),
('vLwTPWXVitRFFFLJ', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:17:49'),
('vLwTPWXVitRFFFLJ', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:17:49'),
('vLwTPWXVitRFFFLJ', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:17:49'),
('x2RqyPEi640Bx5c0', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:26:55'),
('x2RqyPEi640Bx5c0', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:26:55'),
('x2RqyPEi640Bx5c0', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:26:55'),
('x2RqyPEi640Bx5c0', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:26:55'),
('Xd7uGwF2hrKTbD4P', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:14:00'),
('Xd7uGwF2hrKTbD4P', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:14:00'),
('Xd7uGwF2hrKTbD4P', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:14:00'),
('Xd7uGwF2hrKTbD4P', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:14:00'),
('XMA9bdbvm8CXVsYU', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:26:07'),
('XMA9bdbvm8CXVsYU', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:26:07'),
('XMA9bdbvm8CXVsYU', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:26:07'),
('XMA9bdbvm8CXVsYU', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:26:07'),
('XzN8unV1kQRnjha4', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:23:28'),
('XzN8unV1kQRnjha4', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:23:28'),
('XzN8unV1kQRnjha4', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:23:28'),
('XzN8unV1kQRnjha4', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:23:28'),
('ZwcXmVPafgipxtmm', 'GDNGj825', 'OWNR3f8x', 0, NULL, NULL, 'DUS', '2025-12-16 07:06:06'),
('ZwcXmVPafgipxtmm', 'TOKOeLig', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:06:06'),
('ZwcXmVPafgipxtmm', 'TOKOjtqG', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:06:06'),
('ZwcXmVPafgipxtmm', 'TOKOk3CZ', 'OWNR3f8x', 0, NULL, NULL, 'PIECES', '2025-12-16 07:06:06');

INSERT INTO `master_tipe_biaya_operasional` (`KD_TIPE_BIAYA_OPERASIONAL`, `NAMA_TIPE_BIAYA_OPERASIONAL`) VALUES
('TBOPA71z', 'Listrik'),
('TBOPc2gJ', 'Service'),
('TBOPFyrv', 'Gaji Karyawan'),
('TBOPNF2z', 'Pajak Bangunan'),
('TBOPUt7M', 'Air');

INSERT INTO `biaya_operasional` (`ID_COST`, `KD_LOKASI`, `KD_TIPE_BIAYA_OPERASIONAL`, `JUMLAH_BIAYA_UANG`, `PERIODE`, `LAST_UPDATED`) VALUES
('IBOP238AGNELUTNC', 'GDNGj825', 'TBOPc2gJ', 2000000.00, 'TAHUNAN', '2025-11-29 15:10:09'),
('IBOP9VY0GASD69RJ', 'GDNGj825', 'TBOPFyrv', 6000000.00, 'BULANAN', '2025-11-29 15:09:24'),
('IBOPAKB8GHVECYKP', 'GDNGj825', 'TBOPNF2z', 800000.00, 'TAHUNAN', '2025-11-29 15:09:49'),
('IBOPFA9EGF6HB7TF', 'GDNGj825', 'TBOPA71z', 15000000.00, 'BULANAN', '2025-11-29 15:09:40'),
('IBOPQ57SGWRG4QZC', 'GDNGj825', 'TBOPUt7M', 50000.00, 'BULANAN', '2025-11-29 15:09:07');
