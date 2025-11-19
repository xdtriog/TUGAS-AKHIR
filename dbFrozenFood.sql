-- ========================================================
-- Database Frozen Food - Versi Terbaru (XAMPP Ready)
-- Tanggal: 19 November 2025
-- ========================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS BIAYA_OPERASIONAL;
DROP TABLE IF EXISTS DETAIL_NOTA_JUAL;
DROP TABLE IF EXISTS DETAIL_TRANSFER_BARANG;
DROP TABLE IF EXISTS MASTER_BARANG;
DROP TABLE IF EXISTS MASTER_KATEGORI_BARANG;
DROP TABLE IF EXISTS MASTER_LOKASI;
DROP TABLE IF EXISTS MASTER_MEREK;
DROP TABLE IF EXISTS MASTER_SUPPLIER;
DROP TABLE IF EXISTS MASTER_TIPE_BIAYA_OPERASIONAL;
DROP TABLE IF EXISTS MUTASI_BARANG_RUSAK;
DROP TABLE IF EXISTS NOTA_JUAL;
DROP TABLE IF EXISTS PERHITUNGAN_EOQ;
DROP TABLE IF EXISTS PERHITUNGAN_POQ;
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
   ID_COST                       VARCHAR(8) NOT NULL,
   KD_LOKASI                     VARCHAR(8),
   KD_TIPE_BIAYA_OPERASIONAL     VARCHAR(16),
   JUMLAH_BIAYA                  DECIMAL(20,2),
   PERIODE                       ENUM('HARIAN','BULANAN','TAHUNAN'),
   LAST_UPDATED                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   PRIMARY KEY (ID_COST)
);

/*==============================================================*/
/* Table: DETAIL_NOTA_JUAL                                      */
/*==============================================================*/
CREATE TABLE DETAIL_NOTA_JUAL (
   ID_DNJB                       VARCHAR(16) NOT NULL,
   KD_BARANG                     VARCHAR(16),
   ID_NOTA_JUAL                  VARCHAR(16),
   JUMLAH_JUAL_BARANG            INT,
   HARGA_JUAL_BARANG             DECIMAL(20,2),
   PRIMARY KEY (ID_DNJB)
);

/*==============================================================*/
/* Table: DETAIL_TRANSFER_BARANG                                */
/*==============================================================*/
CREATE TABLE DETAIL_TRANSFER_BARANG (
   ID_DETAIL_TRANSFER_BARANG     VARCHAR(16) NOT NULL,
   ID_TRANSFER_BARANG            VARCHAR(16),
   KD_BARANG                     VARCHAR(16),
   JUMLAH_KIRIM                  INT,
   JUMLAH_DITERIMA               INT,
   JUMLAH_DITOLAK                INT,
   STATUS                        ENUM('DIPESAN', 'DIKIRIM', 'DIBATALKAN', 'TIBA'),
   PRIMARY KEY (ID_DETAIL_TRANSFER_BARANG)
);

/*==============================================================*/
/* Table: MASTER_BARANG                                         */
/*==============================================================*/
CREATE TABLE MASTER_BARANG (
   KD_BARANG                     VARCHAR(16) NOT NULL,
   KD_KATEGORI_BARANG            VARCHAR(8),
   KD_SUPPLIER                   VARCHAR(8),
   KD_MEREK_BARANG               VARCHAR(8),
   NAMA_BARANG                   VARCHAR(256),
   BERAT                         INT,
   AVG_HARGA_BELI                DECIMAL(20,2),
   HARGA_JUAL_BARANG             DECIMAL(20,2),
   SATUAN_PERDUS                 INT,
   LAST_UPDATED                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   STATUS                        ENUM('AKTIF', 'TIDAK AKTIF'),
   PRIMARY KEY (KD_BARANG)
);

/*==============================================================*/
/* Table: MASTER_KATEGORI_BARANG                                */
/*==============================================================*/
CREATE TABLE MASTER_KATEGORI_BARANG (
   KD_KATEGORI_BARANG            VARCHAR(8) NOT NULL,
   NAMA_KATEGORI                 VARCHAR(256),
   STATUS                        ENUM('AKTIF', 'TIDAK AKTIF'),
   PRIMARY KEY (KD_KATEGORI_BARANG)
);

/*==============================================================*/
/* Table: MASTER_LOKASI                                         */
/*==============================================================*/
CREATE TABLE MASTER_LOKASI (
   KD_LOKASI                     VARCHAR(8) NOT NULL,
   NAMA_LOKASI                   VARCHAR(256),
   TYPE_LOKASI                   ENUM('gudang', 'toko'),
   ALAMAT_LOKASI                 VARCHAR(256),
   MAX_STOCK_TOTAL               INT,
   SATUAN                        ENUM('PIECES', 'DUS'),
   STATUS                        ENUM('AKTIF', 'TIDAK AKTIF'),
   PRIMARY KEY (KD_LOKASI)
);

/*==============================================================*/
/* Table: MASTER_MEREK                                          */
/*==============================================================*/
CREATE TABLE MASTER_MEREK (
   KD_MEREK_BARANG               VARCHAR(8) NOT NULL,
   NAMA_MEREK                    VARCHAR(256),
   STATUS                        ENUM('AKTIF', 'TIDAK AKTIF'),
   PRIMARY KEY (KD_MEREK_BARANG)
);

/*==============================================================*/
/* Table: MASTER_SUPPLIER                                       */
/*==============================================================*/
CREATE TABLE MASTER_SUPPLIER (
   KD_SUPPLIER                   VARCHAR(8) NOT NULL,
   NAMA_SUPPLIER                 VARCHAR(256),
   ALAMAT_SUPPLIER               VARCHAR(256),
   PIC_SUPPLIER                  VARCHAR(256),
   NOTELP_SUPPLIER               VARCHAR(20),
   STATUS                        ENUM('AKTIF', 'TIDAK AKTIF'),
   PRIMARY KEY (KD_SUPPLIER)
);

/*==============================================================*/
/* Table: MASTER_TIPE_BIAYA_OPERASIONAL                         */
/*==============================================================*/
CREATE TABLE MASTER_TIPE_BIAYA_OPERASIONAL (
   KD_TIPE_BIAYA_OPERASIONAL     VARCHAR(16) NOT NULL,
   NAMA_TIPE_BIAYA_OPERASIONAL   VARCHAR(256),
   PRIMARY KEY (KD_TIPE_BIAYA_OPERASIONAL)
);

/*==============================================================*/
/* Table: MUTASI_BARANG_RUSAK                                   */
/*==============================================================*/
CREATE TABLE MUTASI_BARANG_RUSAK (
   ID_MUTASI_BARANG_RUSAK        VARCHAR(16) NOT NULL,
   KD_BARANG                     VARCHAR(16),
   KD_LOKASI                     VARCHAR(8),
   UPDATED_BY                    VARCHAR(8),
   JUMLAH_MUTASI                 INT,
   HARGA_BARANG                  DECIMAL(20,2),
   WAKTU_MUTASI                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (ID_MUTASI_BARANG_RUSAK)
);

/*==============================================================*/
/* Table: NOTA_JUAL                                             */
/*==============================================================*/
CREATE TABLE NOTA_JUAL (
   ID_NOTA_JUAL                  VARCHAR(16) NOT NULL,
   ID_USERS                      VARCHAR(8),
   KD_LOKASI                     VARCHAR(8),
   WAKTU_NOTA                    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   GRAND_TOTAL                   DECIMAL(20,2),
   PAJAK                         DECIMAL(20,2),
   PRIMARY KEY (ID_NOTA_JUAL)
);

/*==============================================================*/
/* Table: PERHITUNGAN_EOQ                                       */
/*==============================================================*/
CREATE TABLE PERHITUNGAN_EOQ (
   ID_EOQ                        VARCHAR(16) NOT NULL,
   KD_LOKASI                     VARCHAR(8),
   DEMAND_RATE                   INT,
   SETUP_COST                    DECIMAL(20,2),
   HOLDING_COST                  DECIMAL(20,2),
   INTERVAL_HARI                 INT,
   WAKTU_EOQ                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (ID_EOQ)
);

/*==============================================================*/
/* Table: PERHITUNGAN_POQ                                       */
/*==============================================================*/
CREATE TABLE PERHITUNGAN_POQ (
   ID_POQ                        VARCHAR(16) NOT NULL,
   KD_BARANG                     VARCHAR(16),
   KD_LOKASI                     VARCHAR(8),
   ID_EOQ                        VARCHAR(16),
   DEMAND_RATE                   INT,
   SETUP_COST                    DECIMAL(20,2),
   HOLDING_COST                  DECIMAL(20,2),
   LEAD_TIME                     INT,
   INTERVAL_HARI                 INT,
   STOCK_SEKARANG                INT,
   POQ_QUANTITY                  INT,
   WAKTU_POQ                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (ID_POQ)
);

/*==============================================================*/
/* Table: PESAN_BARANG  (sudah ditambah KD_SUPPLIER & status DIKIRIM) */
/*==============================================================*/
CREATE TABLE PESAN_BARANG (
   ID_PESAN_BARANG               VARCHAR(16) NOT NULL,
   KD_LOKASI                     VARCHAR(8),
   KD_BARANG                     VARCHAR(16),
   ID_POQ                        VARCHAR(16),
   KD_SUPPLIER                   VARCHAR(8),
   JUMLAH_PESAN_BARANG_DUS       INT,
   HARGA_PESAN_BARANG_DUS        DECIMAL(20,2),
   TGL_EXPIRED                   DATE,
   BIAYA_PENGIRIMAAN             DECIMAL(20,2),
   JUMLAH_DITERIMA_DUS           INT,
   JUMLAH_DITOLAK_DUS            INT,
   WAKTU_PESAN                   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   WAKTU_ESTIMASI_SAMPAI         TIMESTAMP NULL,
   WAKTU_SAMPAI                  TIMESTAMP NULL,
   STATUS                        ENUM('DIPESAN','DIKIRIM','DIBATALKAN','TIBA'),
   PRIMARY KEY (ID_PESAN_BARANG)
);

/*==============================================================*/
/* Table: STOCK                                                 */
/*==============================================================*/
CREATE TABLE STOCK (
   KD_BARANG                     VARCHAR(16) NOT NULL,
   KD_LOKASI                     VARCHAR(8) NOT NULL,
   UPDATED_BY                    VARCHAR(8),
   JUMLAH_BARANG                 INT DEFAULT 0,
   LAST_UPDATED                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   JUMLAH_MIN_STOCK              INT,
   JUMLAH_MAX_STOCK              INT,
   SATUAN                        ENUM('PIECES', 'DUS'),
   PRIMARY KEY (KD_BARANG, KD_LOKASI)
);

/*==============================================================*/
/* Table: STOCK_HISTORY                                         */
/*==============================================================*/
CREATE TABLE STOCK_HISTORY (
   ID_HISTORY_STOCK              VARCHAR(16) NOT NULL,
   KD_BARANG                     VARCHAR(16),
   KD_LOKASI                     VARCHAR(8),
   UPDATED_BY                    VARCHAR(8),
   JUMLAH_AWAL                   INT,
   JUMLAH_PERUBAHAN              INT,
   JUMLAH_AKHIR                  INT,
   TIPE_PERUBAHAN                ENUM('PEMESANAN', 'TRANSFER', 'OPNAME', 'RUSAK'),
   REF                           VARCHAR(50) NULL,  -- diperbaiki dari varchar(0)
   WAKTU_CHANGE                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (ID_HISTORY_STOCK)
);

/*==============================================================*/
/* Table: STOCK_OPNAME                                          */
/*==============================================================*/
CREATE TABLE STOCK_OPNAME (
   ID_OPNAME                     VARCHAR(16) NOT NULL,
   KD_BARANG                     VARCHAR(16),
   KD_LOKASI                     VARCHAR(8),
   ID_USERS                      VARCHAR(8),
   JUMLAH_SEBENARNYA             INT,
   JUMLAH_SISTEM                 INT,
   SELISIH                       INT,
   HARGA_BARANG                  DECIMAL(20,2),
   WAKTU_OPNAME                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (ID_OPNAME)
);

/*==============================================================*/
/* Table: TRANSFER_BARANG                                       */
/*==============================================================*/
CREATE TABLE TRANSFER_BARANG (
   ID_TRANSFER_BARANG            VARCHAR(16) NOT NULL,
   ID_USERS_PENERIMA             VARCHAR(8),
   ID_USERS_PENGIRIM             VARCHAR(8),
   KD_LOKASI_ASAL                VARCHAR(8),
   KD_LOKASI_TUJUAN              VARCHAR(8),
   WAKTU_PESAN_TRANSFER          TIMESTAMP NULL,
   WAKTU_KIRIM_TRANSFER          TIMESTAMP NULL,
   WAKTU_TIBA_TRANSFER           TIMESTAMP NULL,
   STATUS                        ENUM('DIPESAN', 'DIKIRIM', 'DIBATALKAN', 'TIBA'),
   PRIMARY KEY (ID_TRANSFER_BARANG)
);

/*==============================================================*/
/* Table: USERS                                                 */
/*==============================================================*/
CREATE TABLE USERS (
   ID_USERS                      VARCHAR(8) NOT NULL,
   KD_LOKASI                     VARCHAR(8),
   KD_SUPPLIER                   VARCHAR(8),
   NAMA                          VARCHAR(256),
   USERNAME                      VARCHAR(256),
   PASSWORD                      VARCHAR(256),
   STATUS                        ENUM('AKTIF', 'TIDAK AKTIF'),
   PERMISION                     TINYINT(1),
   PRIMARY KEY (ID_USERS)
);

/*==============================================================*/
/* Foreign Keys (semua sudah diperbarui)                        */
/*==============================================================*/

ALTER TABLE BIAYA_OPERASIONAL ADD CONSTRAINT FK_BIAYA_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE BIAYA_OPERASIONAL ADD CONSTRAINT FK_BIAYA_TIPE FOREIGN KEY (KD_TIPE_BIAYA_OPERASIONAL) REFERENCES MASTER_TIPE_BIAYA_OPERASIONAL (KD_TIPE_BIAYA_OPERASIONAL);

ALTER TABLE DETAIL_NOTA_JUAL ADD CONSTRAINT FK_DNJ_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE DETAIL_NOTA_JUAL ADD CONSTRAINT FK_DNJ_NOTA FOREIGN KEY (ID_NOTA_JUAL) REFERENCES NOTA_JUAL (ID_NOTA_JUAL);

ALTER TABLE DETAIL_TRANSFER_BARANG ADD CONSTRAINT FK_DT_TRANSFER FOREIGN KEY (ID_TRANSFER_BARANG) REFERENCES TRANSFER_BARANG (ID_TRANSFER_BARANG);
ALTER TABLE DETAIL_TRANSFER_BARANG ADD CONSTRAINT FK_DT_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);

ALTER TABLE MASTER_BARANG ADD CONSTRAINT FK_BARANG_MEREK FOREIGN KEY (KD_MEREK_BARANG) REFERENCES MASTER_MEREK (KD_MEREK_BARANG);
ALTER TABLE MASTER_BARANG ADD CONSTRAINT FK_BARANG_KATEGORI FOREIGN KEY (KD_KATEGORI_BARANG) REFERENCES MASTER_KATEGORI_BARANG (KD_KATEGORI_BARANG);
ALTER TABLE MASTER_BARANG ADD CONSTRAINT FK_BARANG_SUPPLIER FOREIGN KEY (KD_SUPPLIER) REFERENCES MASTER_SUPPLIER (KD_SUPPLIER);

ALTER TABLE MUTASI_BARANG_RUSAK ADD CONSTRAINT FK_MUTASI_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE MUTASI_BARANG_RUSAK ADD CONSTRAINT FK_MUTASI_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE MUTASI_BARANG_RUSAK ADD CONSTRAINT FK_MUTASI_USER FOREIGN KEY (UPDATED_BY) REFERENCES USERS (ID_USERS);

ALTER TABLE NOTA_JUAL ADD CONSTRAINT FK_NOTA_USER FOREIGN KEY (ID_USERS) REFERENCES USERS (ID_USERS);
ALTER TABLE NOTA_JUAL ADD CONSTRAINT FK_NOTA_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);

ALTER TABLE PERHITUNGAN_EOQ ADD CONSTRAINT FK_EOQ_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);

ALTER TABLE PERHITUNGAN_POQ ADD CONSTRAINT FK_POQ_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE PERHITUNGAN_POQ ADD CONSTRAINT FK_POQ_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE PERHITUNGAN_POQ ADD CONSTRAINT FK_POQ_EOQ FOREIGN KEY (ID_EOQ) REFERENCES PERHITUNGAN_EOQ (ID_EOQ);

ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_POQ FOREIGN KEY (ID_POQ) REFERENCES PERHITUNGAN_POQ (ID_POQ);
ALTER TABLE PESAN_BARANG ADD CONSTRAINT FK_PESAN_SUPPLIER FOREIGN KEY (KD_SUPPLIER) REFERENCES MASTER_SUPPLIER (KD_SUPPLIER);

ALTER TABLE STOCK ADD CONSTRAINT FK_STOCK_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE STOCK ADD CONSTRAINT FK_STOCK_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE STOCK ADD CONSTRAINT FK_STOCK_USER FOREIGN KEY (UPDATED_BY) REFERENCES USERS (ID_USERS);

ALTER TABLE STOCK_HISTORY ADD CONSTRAINT FK_HISTORY_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE STOCK_HISTORY ADD CONSTRAINT FK_HISTORY_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE STOCK_HISTORY ADD CONSTRAINT FK_HISTORY_USER FOREIGN KEY (UPDATED_BY) REFERENCES USERS (ID_USERS);

ALTER TABLE STOCK_OPNAME ADD CONSTRAINT FK_OPNAME_BARANG FOREIGN KEY (KD_BARANG) REFERENCES MASTER_BARANG (KD_BARANG);
ALTER TABLE STOCK_OPNAME ADD CONSTRAINT FK_OPNAME_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE STOCK_OPNAME ADD CONSTRAINT FK_OPNAME_USER FOREIGN KEY (ID_USERS) REFERENCES USERS (ID_USERS);

ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRANSFER_PENGIRIM FOREIGN KEY (ID_USERS_PENGIRIM) REFERENCES USERS (ID_USERS);
ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRANSFER_PENERIMA FOREIGN KEY (ID_USERS_PENERIMA) REFERENCES USERS (ID_USERS);
ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRANSFER_ASAL FOREIGN KEY (KD_LOKASI_ASAL) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE TRANSFER_BARANG ADD CONSTRAINT FK_TRANSFER_TUJUAN FOREIGN KEY (KD_LOKASI_TUJUAN) REFERENCES MASTER_LOKASI (KD_LOKASI);

ALTER TABLE USERS ADD CONSTRAINT FK_USERS_LOKASI FOREIGN KEY (KD_LOKASI) REFERENCES MASTER_LOKASI (KD_LOKASI);
ALTER TABLE USERS ADD CONSTRAINT FK_USERS_SUPPLIER FOREIGN KEY (KD_SUPPLIER) REFERENCES MASTER_SUPPLIER (KD_SUPPLIER);
INSERT INTO USERS (ID_USERS, KD_LOKASI, KD_SUPPLIER, NAMA, USERNAME, PASSWORD, STATUS, PERMISION) VALUES ('UOWNR3f8x9p2m7q5r1t2', NULL, NULL, 'Pemilik Utama', 'owner', 'password', 'AKTIF', 1);
/* ============================================================= */
/* DATABASE SIAP 100% – IMPORT LANGSUNG DI phpMyAdmin!          */
/* ============================================================= */