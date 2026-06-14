# DropCode — File & Text Sharing via Code

A single-file PHP webapp to share files and text using a short 6-character code.

[![Live Demo](https://img.shields.io/badge/Live%20Demo-Visit%20Site-7c6af7?style=for-the-badge)](https://dropcode.freepage.cc)

## Features
- Share **text** (notes, code snippets, passwords, etc.)
- Share **files** up to 20 MB
- Auto-generated **6-character code** (e.g. `A1B2C3`)
- **Expiry** options: 30minutes / 1h / 6h / 24h
- Download counter per share
- Dark, minimal UI — no database needed (JSON-based storage)

## Requirements
- PHP 7.4+ (uses `random_bytes`, arrow functions)
- Apache or Nginx with write permissions on the `uploads/` folder

## Setup

### 1. Copy files
```
your-webroot/
  index.php
  uploads/        ← auto-created, must be writable
```

### 2. Set permissions
```bash
mkdir -p uploads
chmod 755 uploads
```

### 3. Apache — add a `.htaccess` to protect uploads
```apache
<Directory uploads>
  Options -Indexes
  <FilesMatch ".*">
    Order Allow,Deny
    Deny from all
  </FilesMatch>
</Directory>
```

Or in your `httpd.conf` / virtual host, block direct access to `uploads/`.

### 4. Nginx — block direct access
```nginx
location /uploads/ {
    deny all;
    return 404;
}
```

### 5. PHP upload limits (php.ini)
```ini
upload_max_filesize = 20M
post_max_size       = 22M
```

## How it works
- All share metadata is stored in `uploads/shares.json`
- Files are stored as `<CODE>.<ext>` inside `uploads/`
- Expired shares are purged on every page load
- No database, no login, no dependencies

## Customization
| Constant | Default | Description |
|---|---|---|
| `MAX_FILE_SIZE` | 20 MB | Max upload size |
| `CODE_LENGTH` | 6 | Length of share codes |
| `DEFAULT_EXPIRY_HOURS` | 24 | Pre-selected expiry |

All constants are at the top of `index.php`.
