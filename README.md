# Zebilo Core

هسته API و پنل اختصاصی تأمین‌کننده برای فروشگاه WooCommerce زبیلو.

## پنل تأمین‌کننده

پس از فعال‌سازی افزونه، صفحه `/supplier-panel/` ساخته می‌شود. کاربر دارای نقش **تأمین‌کننده زبیلو** فقط می‌تواند قیمت و موجودی کالاهای منتشرشده را تغییر دهد و به `wp-admin` دسترسی ندارد.

## Endpointها

Base URL: `/wp-json/zebilo/v1`

- `GET /supplier/me`
- `GET /supplier/stats`
- `GET /supplier/products`
- `GET /supplier/categories`
- `POST /supplier/products/bulk`
- `GET /supplier/history`

Endpointهای خصوصی فقط برای کاربر دارای capability `zebilo_manage_supplier_products` هستند و پنل از Cookie Authentication وردپرس + REST Nonce استفاده می‌کند.

## نصب

1. افزونه را در `wp-content/plugins/zebilo-core/` قرار دهید.
2. افزونه را فعال کنید.
3. یک کاربر وردپرس با نقش **تأمین‌کننده زبیلو** بسازید.
4. آدرس `/supplier-panel/` را در اختیار تأمین‌کننده قرار دهید.

## وضعیت موجودی

- بیشتر از ۵: موجود
- ۱ تا ۵: رو به اتمام
- صفر: ناموجود

ثبت موجودی، مدیریت موجودی WooCommerce را فعال می‌کند و وضعیت را بر اساس مقدار جدید تنظیم می‌کند.

## تاریخچه

هر تغییر قیمت یا موجودی در جدول `wp_zebilo_supplier_history` ثبت می‌شود.
