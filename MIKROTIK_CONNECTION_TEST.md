# اختبار الاتصال بـ MikroTik - Backend

## 🔧 اختبار الاتصال من Backend

### الطريقة 1: من التطبيق (موصى بها)

1. سجل الدخول كـ **Network Owner**
2. اذهب إلى **"إدارة الشبكات"**
3. اضغط على أيقونة **"اختبار الاتصال"** بجانب الشبكة
4. ستظهر رسالة نجاح أو فشل

### الطريقة 2: من API مباشرة

```bash
# POST request
POST /api/owner/networks/{network_id}/test-connection
Headers:
  Authorization: Bearer {token}
```

### الطريقة 3: من Laravel Tinker

```bash
cd backend
php artisan tinker
```

```php
use App\Models\Network;
use App\Services\MikroTikService;

$network = Network::find(1); // استبدل 1 بـ ID الشبكة
$service = new MikroTikService();
$result = $service->testConnection($network);
print_r($result);
```

---

## 🔍 فحص الأخطاء

### إذا فشل الاتصال:

1. **تحقق من Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **تحقق من MikroTik:**
   - افتح Winbox
   - **IP → Services** → تأكد من تفعيل API
   - **System → Users** → تأكد من المستخدم

3. **تحقق من الشبكة:**
   ```bash
   ping 192.168.1.1  # استبدل بـ IP MikroTik
   telnet 192.168.1.1 8728  # للتحقق من Port
   ```

---

## 📝 ملاحظات

- كلمات المرور مشفرة في قاعدة البيانات
- Service يقوم بفك التشفير تلقائياً عند الاتصال
- Timeout: 5 ثواني للاتصال
