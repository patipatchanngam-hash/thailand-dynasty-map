# Thailand Dynasty Map

เว็บแอปสำรวจกษัตริย์ของอาณาจักรต่าง ๆ ในไทยและเอเชียตะวันออกเฉียงใต้ มี 3 หน้า:

- **แผนที่** (`index.php`) — ค้นหากษัตริย์ตามปี (พ.ศ./ค.ศ./ร.ศ.), รัชกาลที่ หรือพระนาม และแสดงตำแหน่งอาณาจักรบนแผนที่
- **แผนผังราชวงศ์** (`family_tree.php`) — ผังเครือญาติของแต่ละอาณาจักร
- **ไทม์ไลน์** (`time.php`) — ช่วงครองราชย์ของแต่ละอาณาจักรเป็นแถบเวลา ซูมและเปลี่ยนศักราชได้

## วิธีรัน (แนะนำ: Docker)

ต้องมี [Docker Desktop](https://www.docker.com/products/docker-desktop/)

```bash
docker compose up -d --build
```

แล้วเปิด **http://localhost:8080**

- ครั้งแรกระบบจะกู้คืนฐานข้อมูลจาก `Database/kingdata.sql` ให้อัตโนมัติ
- PostgreSQL เปิดที่พอร์ต `5433` บนเครื่อง (user `postgres` / password `root`)
- หยุด: `docker compose down` · ล้างฐานข้อมูลแล้วกู้คืนใหม่: `docker compose down -v`

## Deploy บน Render (ฟรี) + Neon

1. สร้างฐานข้อมูลฟรีที่ [Neon](https://neon.tech) (เลือก region Singapore) แล้วคัดลอก connection string
2. กู้คืนข้อมูลเข้า Neon (ต้องเปิด Docker Desktop):
   ```bash
   docker run --rm -v "${PWD}/Database:/dump" postgres:17 pg_restore --no-owner --no-privileges -d "<NEON_URL>" /dump/kingdata.sql
   ```
3. push โปรเจกต์ขึ้น GitHub
4. ที่ [Render](https://render.com) → New → Blueprint → เลือก repo (อ่าน `render.yaml`) → กรอก `DATABASE_URL` เป็น connection string ของ Neon

## รันแบบไม่ใช้ Docker (XAMPP / PHP ของตัวเอง)

1. ติดตั้ง PHP 8.1+ และเปิด extension `pdo_pgsql`
2. กู้คืนฐานข้อมูลเข้า PostgreSQL:
   ```bash
   pg_restore --no-owner -U postgres -d postgres Database/kingdata.sql
   ```
3. ถ้าค่าเชื่อมต่อต่างจากค่าเริ่มต้น (`localhost:5432`, `postgres`/`root`) ให้ตั้ง environment variables:
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
4. รันเซิร์ฟเวอร์: `php -S localhost:8080 -t Frontend`

## โครงสร้าง

```
Database/kingdata.sql           ไฟล์ pg_dump (custom format)
Database/restore.sh             สคริปต์กู้คืนฐานข้อมูลตอนเริ่ม container
Frontend/config.php             การเชื่อมต่อ DB + รายชื่อ/สีอาณาจักร (ใช้ร่วมทุกหน้า)
Frontend/assets/                CSS และรูปภาพที่ใช้ร่วมกัน
Frontend/index.php              แผนที่
Frontend/family_tree.php        แผนผังราชวงศ์
Frontend/fetch_family_data.php  API JSON สำหรับแผนผังราชวงศ์
Frontend/time.php               ไทม์ไลน์
```
