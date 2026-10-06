# 🚀 Ubuntu Server Installation & Database Setup Guide
**Project:** Network Support System  
**Document Version:** 1.0  
**Updated:** August 2026

---

## 📋 Table of Contents
1. [Prerequisites & Packages](#1-prerequisites--packages)
2. [Database Creation (MariaDB / MySQL)](#2-database-creation-mariadb--mysql)
3. [Folder Permissions on Ubuntu](#3-folder-permissions-on-ubuntu)
4. [File Upload Guide (WinSCP / FileZilla)](#4-file-upload-guide-winscp--filezilla)
5. [Web Installation Wizard Steps](#5-web-installation-wizard-steps)
6. [Post-Installation & Security](#6-post-installation--security)
7. [Troubleshooting & FAQs](#7-troubleshooting--faqs)

---

## 1. Prerequisites & Packages

If you haven't installed Apache, PHP, and MariaDB/MySQL on Ubuntu yet, run:

```bash
# Update system repositories
sudo apt update && sudo apt upgrade -y

# Install Apache web server
sudo apt install apache2 -y

# Install MariaDB or MySQL server
sudo apt install mariadb-server -y

# Install PHP and required extensions
sudo apt install php libapache2-mod-php php-mysql php-mbstring php-xml php-curl php-zip -y

# Enable rewrite module and restart Apache
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 2. Database Creation (MariaDB / MySQL)

### Step 2.1: Log into MySQL / MariaDB shell
```bash
sudo mysql -u root -p
```
*(Press Enter if you have not set a root password, or enter your MySQL root password)*

### Step 2.2: Execute these SQL commands:

```sql
-- 1. Create the database
CREATE DATABASE IF NOT EXISTS network_support CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Create a dedicated database user
-- (Change 'StrongPassword123' to your desired password)
CREATE USER IF NOT EXISTS 'network_support'@'localhost' IDENTIFIED BY 'server2026';

-- 3. Grant full privileges to the database
GRANT ALL PRIVILEGES ON network_support.* TO 'network_support'@'localhost';

-- 4. Apply changes
FLUSH PRIVILEGES;

-- 5. Exit MySQL
EXIT;
```

---

## 3. Folder Permissions on Ubuntu

The web server (`www-data`) must have write permissions to create `config.php` and lock files:

```bash
# Give ownership to your SSH user and Apache (www-data)
sudo chown -R $USER:www-data /var/www/html/network_support

# Set directory permissions
sudo chmod -R 775 /var/www/html/network_support
```

---

## 4. File Upload Guide (WinSCP / FileZilla)

1. Open **WinSCP** or **FileZilla**.
2. Connect to your Ubuntu Server IP (e.g. `192.168.16.127`), Port `22`, using your SSH credentials.
3. Target remote directory:
   ```
   /var/www/html/network_support/
   ```
4. Transfer all project files from your local folder `D:\xampp\htdocs\network_support\`.

> 💡 **If WinSCP gives "Permission Denied" warning on upload:**
> - Click **"Skip all"** on the popup.
> - Or in WinSCP: **Options → Preferences → Transfer → Default/Edit → Uncheck "Preserve timestamp" & Check "Ignore permission errors"**.

---

## 5. Web Installation Wizard Steps

Open your browser and navigate to:
```
http://<YOUR_UBUNTU_SERVER_IP>/network_support/install/
```
*(Example: `http://192.168.16.127/network_support/install/`)*

### 🔹 Step 1: Requirements Check
- All items should show **`✓ Pass`** (Green).
- Click **Continue →**

### 🔹 Step 2: Database Configuration
Fill in the form:
- **Database Host:** `localhost`
- **Port:** `3306`
- **Database Name:** `network_support`
- **Username:** `admin_user`
- **Password:** `StrongPassword123` *(from Step 2.2)*

👉 Click **🔌 Test Connection** → Confirm it says *"Connected successfully! ✅"*  
👉 Click **Continue →**

### 🔹 Step 3: Administrator Account Setup
Fill in your initial admin user details:
- **Full Name:** `System Administrator`
- **Username:** `admin`
- **Email Address:** `admin@example.com`
- **Password:** *(choose your password - min 8 chars)*
- **Confirm Password:** *(re-enter password)*

👉 Click **Review & Install →**

### 🔹 Step 4: Automated Installation
- The wizard automatically executes `database/schema.sql`, generates `config.php`, creates the admin account, and locks the installer.
- Progress bar reaches **100%**.

### 🔹 Step 5: Finished!
- Click **Go to Login Page →**
- Sign in with your admin credentials!

---

## 6. Post-Installation & Security

1. The installer automatically locks itself by creating `install/installed.lock`.
2. *(Optional)* You may delete or rename the `install/` directory:
   ```bash
   sudo rm -rf /var/www/html/network_support/install
   ```

---

## 7. Troubleshooting & FAQs

| Problem | Cause | Solution |
|---|---|---|
| `config.php Writable: Not Writable ✗` | Missing write permissions | Run `sudo chmod -R 775 /var/www/html/network_support` |
| Database connection error | Wrong user/password or service stopped | Verify `sudo systemctl status mariadb` and test with `mysql -u admin_user -p` |
| White screen / 500 error | Missing PHP PDO module | Run `sudo apt install php-mysql php-mbstring && sudo systemctl restart apache2` |
| Reset & Reinstall | System locked | Delete `install/installed.lock` and run installer again |
