# Şirket İçi Mesajlaşma — V1 Sonuç Raporu

## IMPLEMENTED

İlk inceleme: PHP 8.5, Laravel 13.35, Inertia Laravel 3.5, Vue 3, Tailwind 4, Vite Plus ve Pest 5 vardı. Proje boş Vue starter kit idi; hazır auth/admin ekranları yoktu. Resend 1.6 zaten kuruluydu. Mevcut `.env` geliştirme bağlantısı SQLite; gerçek MySQL bağlantısı değiştirilmedi.

- Login/logout, forgot/reset password, davet ile ilk şifre oluşturma; public kayıt rotası yok.
- Admin dashboard, 20 kayıtlık kullanıcı listesi, oluşturma/düzenleme, aktif/pasif ve yeniden davet/reset maili.
- Davet gönderildi/gönderilemedi ve şifre oluşturuldu durumları. Pasif hesap için mail gönderilmez.
- Kendi admin hesabını pasif yapma veya rolünü düşürme engeli.
- E-posta değiştiğinde önceki token/oturum/remember erişimi iptal edilir, yeni adrese şifre kurulumu gerekir.
- Genel kanalı, plain text mesaj, gönderme/loading/hata durumları, mobil uyumlu yerleşim ve altta mesaj kutusu.
- İlk açılışta son 40 mesaj; eski mesajlar `before_id`, bağlantı sonrası eksikler `after_id` ile sınırlı sorgularla alınır.
- Mevcut Inertia/Vite mimarisi korunur; frontend endpointleri Wayfinder ile üretilir.

Yetki sınırları:

| İşlem                             | Aktif admin | Aktif employee | Pasif kullanıcı / misafir |
| --------------------------------- | ----------- | -------------- | ------------------------- |
| Sohbet, mesaj geçmişi ve gönderim | İzinli      | İzinli         | Engelli                   |
| Private kanal aboneliği           | İzinli      | İzinli         | Engelli                   |
| Admin ve kullanıcı endpointleri   | İzinli      | 403            | Engelli                   |

## DATABASE

Yeni migrationlar mevcut `users` tablosuna `role`, `is_active`, `invited_at`, `invitation_failed_at`, `password_set_at`, `last_login_at` ekler. Eski migration değiştirilmez.

`channels`: `name`, unique `slug`; `ChannelSeeder` tekrar çalıştırıldığında duplicate oluşturmadan **Genel / general** kanalını oluşturur.

`messages`: channel/user foreign key, `body`, timestamps, soft delete ve kanal/geçmiş sorgusu için bileşik index. Fiziksel kullanıcı/kanal silme FK ile engellenir; kullanıcı yönetimi pasifleştirme üzerinden yürür.

Mevcut `password_reset_tokens`, `sessions`, `jobs`, `job_batches`, `failed_jobs` korunur. Kullanıcı ve kanal mesaj ilişkileri tanımlıdır. DatabaseSeeder artık varsayılan parola ile örnek hesap oluşturmaz.

**Mevcut geliştirme veya production veritabanında migration/seeder çalıştırılmadı.** Testler ayrı SQLite `:memory:` veritabanında migrationları çalıştırır. MySQL üzerinde şema ve bütünlük doğrulaması yayın aşamasında gereklidir.

## SECURITY

- Backend Gate ve Form Request authorization; admin işlemleri frontend gizlemesine bağlı değildir.
- User rol/aktivasyon ve mesaj kimlik alanları genel mass assignment kapsamına alınmaz. Mesaj sahibi oturumdan, kanal backend seçiminden gelir.
- Trim sonrası boş mesaj ve 4000 karakter sınırı; Unicode boşlukları da temizlenir. Vue metin interpolasyonu kullanılır, `v-html` yoktur.
- Login: hesap/IP başına 5/dakika ve IP başına 30/dakika. Password endpointleri: IP başına 5/dakika. Davet yenileme: admin başına 10/dakika. Mesaj: kullanıcı başına varsayılan 25/dakika, config ile değişir.
- Laravel password broker: süreli, hash olarak saklanan token; yeniden gönderimde yeni token, 60 saniye broker throttle. Başarılı resetten sonra token tekrar kullanılamaz.
- Login session ID yenilemesi; logout session/CSRF iptali; password reset ve pasifleştirmede database session/remember token iptali.
- Tüm yazma endpointleri Laravel web middleware/CSRF davranışını korur. Echo ve fetch güncel XSRF cookie değerini kullanır.
- Reset URL kaynağı güvenilir `APP_URL` config değeridir; gelen Host header kullanılmaz.
- Pasif hesap bir sonraki korunan istekte oturumdan çıkarılır. Açık sohbet 30 saniyede bir oturumunu kontrol eder.
- Resend paketinin kullanılmayan webhook rotaları V1 config içinde kapalıdır.
- Onaylanan npm güvenlik düzeltmeleri: Vite Plus/core 0.3.3 eşleştirmesi ve shell-quote 1.11 override. npm audit: 0 açık; Composer kurulumu sırasında audit: açık yok.

## REALTIME

Laravel Reverb **1.12**, Laravel Echo **2.5** ve Pusher JS **8.6** kuruldu. Reverb için Guzzle 7/PSR7 2 uyumlu bağımlılık sürümleri lockfile içinde çözümlendi; mevcut Resend entegrasyonu korundu.

`MessageService` kaydı transaction içinde oluşturur. `MessageCreated`, `ShouldBroadcastNow` ve `ShouldDispatchAfterCommit` kullanır. V1 için queue worker gerektirmez. Rollback durumunda event gönderilmediği test edilir.

Private kanal: `company.general` (wire adı `private-company.general`). `/broadcasting/auth` authenticated/active oturum ve `routes/channels.php` callback ile korunur.

Event yalnızca `message_id` ve `channel_id` taşır. İçerik aktif oturum gerektiren `messages.show` endpointinden alınır. Böylece pasifleştirmeden önce açılmış socket yeni mesaj metinlerini vermez; socket bir süre daha kimlik eventlerini alabilir. Mevcut sunucu uygulamasında client events kapatılmalıdır; bu projenin Reverb config'i bunları kapatır.

Frontend mesaj kimliğine göre tekilleştirir. Abonelik/yeniden bağlantıda ileri sayfalama ile eksikler alınır. Eski geçmiş yüklenirken scroll konumu korunur. Broadcast hatasında mesaj kaydı korunur ve canlı gönderimin başarısız olduğu yanıt/UI üzerinden gösterilir. `log`/`null` broadcaster canlı iletim başarılı gibi raporlanmaz. Sunucunun event isteğini kabul etmesi, bütün istemcilerin mesajı aldığını kanıtlamaz.

`ws.tepenetguvenlik.com` örnek host olarak kullanıldı; key/secret/id tahmin edilmedi. Var olan Reverb sunucusunda bu uygulamaya ait matching credentials ve web origin ayarı gereklidir.

## MAIL

Resend Laravel/Notifications akışı synchronous çalışır; worker gerektirmez. Yeni veya şifresi henüz oluşturulmamış kullanıcı `UserInvitationNotification`, şifresini oluşturmuş kullanıcı Laravel `ResetPassword` notification alır.

Akış: admin controller → CreateEmployee → kalıcı kullanıcı kaydı → SendInvitation → Password broker → User notification → configured mailer → Resend.

Mail başarısızsa kullanıcı korunur, `invitation_failed_at` kaydedilir, admin açıklayıcı durum görür. Başarılı yeniden gönderim veya şifre kurulumu hata durumunu temizler. Loglar yalnızca kullanıcı/mesaj kimliği ve exception sınıfını içerir; provider cevabı, token, API key veya Authorization header loglanmaz.

Testlerde Notification fake/mock kullanıldı; gerçek Resend API çağrısı yapılmadı. Gerçek teslimat, Resend'de doğrulanmış gönderici ve hedef inbox ile manuel doğrulanmalıdır.

## TESTS

- `php artisan test --compact`: **74 test / 283 assertion**, hepsi geçti.
- Auth/admin/message fazlarında ilgili dar test grupları ayrıca çalıştırıldı.
- Admin → davet → şifre kurulumu → employee login → Genel → mesaj → employee admin 403 → logout bütünleşik feature testi geçti.
- Private kanal auth, token yenileme/expiry/reuse, inactive/invalid state, mail failure, mesaj validation/pagination/rate limit, broadcast failure ve rollback coverage mevcut.
- `vendor/bin/pint --dirty --format agent`: geçti.
- `vendor/bin/phpstan analyse --no-progress --debug --memory-limit=512M`: 0 hata. Sandbox socket kısıtı nedeniyle analizin seri `--debug` modu kullanıldı.
- `npm run check`, `npm run types:check`, `npm run build`: geçti.
- `npm ci --dry-run --ignore-scripts`: lockfile temiz kurulum çözümlemesi geçti.
- `git diff --check`: geçti.

Mevcut uygulama logunda önceki testlerin Vite manifest hataları ve bilerek oluşturulan mail/broadcast hataları vardı; manifest hataları build ile düzeltildi. Yeni test logları gerçek uygulama logunu kirletmemesi için `null` log kanalını kullanır. Production ve tarayıcı logları henüz canlı kullanımda doğrulanmadı.

## ENVIRONMENT

Gerçek secret değerleri raporlanmaz. `.env.example` placeholder içerir. Gerekli değişken adları:

```text
APP_NAME
APP_ENV
APP_KEY
APP_DEBUG
APP_URL
DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
MAIL_MAILER
RESEND_API_KEY
MAIL_FROM_ADDRESS
MAIL_FROM_NAME
BROADCAST_CONNECTION
REVERB_APP_ID
REVERB_APP_KEY
REVERB_APP_SECRET
REVERB_HOST
REVERB_PORT
REVERB_SCHEME
REVERB_ALLOWED_ORIGINS
VITE_REVERB_APP_KEY
VITE_REVERB_HOST
VITE_REVERB_PORT
VITE_REVERB_SCHEME
SESSION_DRIVER
SESSION_SECURE_COOKIE
SESSION_SAME_SITE
CACHE_STORE
QUEUE_CONNECTION
CHAT_MESSAGE_RATE_LIMIT
```

Mevcut `.env` içinde Resend alanları var; Reverb alanları yok. Production için `APP_ENV=production`, `APP_DEBUG=false`, MySQL, HTTPS `APP_URL`, `MAIL_MAILER=resend`, `BROADCAST_CONNECTION=reverb`, database session/cache/queue ve secure cookie ayarlarını kullanın. HTTP yerel geliştirmede secure cookie ayarını ortamınıza göre değiştirin. Vite değişkenleri build sırasında okunur; değişiklikten sonra yeniden build gerekir. Reverb origin ayarı dışarıdaki mevcut websocket sunucusunda da uygulanmalıdır.

## DEPLOYMENT

cPanel üzerinde henüz deployment yapılmadı. Sıra:

1. Hedef MySQL veritabanını/yetkileri hazırlayın; mevcut veritabanı varsa yayın öncesi yedeğini alın. PHP CLI/web sürümünün 8.5 ve gerekli Laravel/MySQL uzantılarının açık olduğunu doğrulayın.
2. Uygulamayı domain document root'u `public/` olacak şekilde yerleştirin. `.env`, vendor ve uygulama kaynakları webden erişilebilir olmamalı. `storage` ve `bootstrap/cache` yazılabilir olmalı.
3. Gerçek `.env` ayarlarını backend üzerinde hazırlayın. APP_KEY mevcutsa koruyun; yeni kurulumda `php artisan key:generate --no-interaction` kullanın. HTTPS sertifikasını etkinleştirin.
4. Mevcut Reverb sunucusunda uygulama credentials/origin eşleşmesini doğrulayın. cPanel uygulaması hazır dış Reverb sunucusuna HTTPS ile yayın yapar; ikinci websocket daemon başlatmanız gerekmez.
5. `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` çalıştırın. Build ortamında `npm ci`, ardından doğru public Reverb/Vite env değerleriyle `npm run build` çalıştırıp `public/build` çıktısını yükleyin. Production üzerinde stale `public/hot` dosyası bulunmamalı.
6. Hedef DB ve bakım penceresini kontrol ettikten sonra **sizin onayınızla** `php artisan migrate --force --no-interaction` çalıştırın. `migrate:fresh` kullanmayın. Ardından `php artisan db:seed --class=ChannelSeeder --force --no-interaction` çalıştırın.
7. İlk admini **interaktif** `php artisan chat:create-admin` ile oluşturun. Şifre gizli prompttan alınır; command-line, source veya seeder içine yazılmaz. Mevcut kullanıcılar migration sonrası employee/pending olarak kalır; uygun hesapları güvenilir admin ile yeniden davet edin.
8. `php artisan optimize --no-interaction` ile config/route/event/view cachelerini oluşturun. Her yeni env/build değişikliğinde cache ve build çıktılarını yenileyin.
9. Manuel kabul: admin girişi → çalışan oluşturma → gerçek inbox daveti → şifre kurulumu → employee girişi → Genel → mesajın MySQL'de saklanması → Chrome/Firefox arasında refresh olmadan iletim ve gönderen tarafında tek kayıt. Sonra employee admin 403, pasif login/oturum reddi, forgot password, logout ve mobil ekran/klavye kontrolleri.
10. Resend teslimatını, websocket aboneliğini ve güncel Laravel/tarayıcı loglarını kontrol edin. Test ortamında `php artisan test --compact` tam paketini yayın öncesi yeniden çalıştırın.

Database queue altyapısı hazırdır; worker açılmasa da bu V1'in mail/realtime akışı synchronous çalışır. Scheduler, backup ve monitoring bu sürüme eklenmedi.

## CAPACITOR

Frontend Vue/Inertia olarak korundu; responsive `dvh` yerleşim, mobilde gönder butonu, safe-area composer ve plaintext içerik hazırlandı. **Capacitor/Android dependency veya native proje eklenmedi:** web V1'in canlı kabul senaryosu tamamlanmadığı için bu faza geçilmedi.

Canlı web kabulünden sonra minimum Capacitor kurulumu, Android projesi, HTTPS production uygulama URL'si ve WebView cookie/CSRF/keyboard ayarları doğrulanmalıdır. Inertia frontend server routing/session kullanır; yalnızca `public/build` dosyalarını native uygulamaya kopyalamak auth ve routing'i bağımsız çalıştırmaz. Android üzerinde login → chat → send/receive → logout ayrıca test edilmelidir. Push bu V1'de yoktur.

## PRODUCTION SAFETY

`.env` değiştirilmedi ve git'e eklenmedi. Resend key ve Reverb secret yalnızca backend config/environment içindedir. Frontend'e sadece public Reverb app key/host/port/scheme gider. Mail sender/name env üzerinden yönetilir. Hata yollarında provider yanıtı ve secret/Authorization bilgisi loglanmaz.

## KNOWN LIMITATIONS / TOMORROW

**Production kabulü tamamlanmış sayılmaz:** hedef MySQL migration/şema kontrolü, gerçek Resend inbox teslimatı, iki açık tarayıcı arasında mevcut Reverb sunucusunda canlı iletim, mobil cihaz/WebView ve production log kontrolleri bekliyor. Tarayıcı kontrol aracı/log kaydı mevcut değildi; yalnızca automated backend ve frontend build/type/lint kanıtları var.

Bilinçli olarak ertelendi: moderation, read receipts, mentions, mention digest mail, audit logs, monitoring, backup, push notifications ve kullanıcı isteğinde V1 dışı bırakılan typing/online/reaction/reply/attachment/search özellikleri. Yarım moderation production akışına bağlanmadı.

## ÖNEMLİ DOSYALAR

- `database/migrations/2026_10_06_190431_add_employee_fields_to_users_table.php`
- `database/migrations/2026_10_06_190432_create_channels_table.php`
- `database/migrations/2026_10_06_190433_create_messages_table.php`
- `database/seeders/ChannelSeeder.php`, `DatabaseSeeder.php`; User/Channel/Message factories.
- `app/Models/User.php`, `Channel.php`, `Message.php`.
- `app/Actions/Users/CreateEmployee.php`, `UpdateEmployee.php`, `SendInvitation.php`.
- `app/Http/Controllers/Auth/SessionController.php`, `PasswordResetLinkController.php`, `NewPasswordController.php`.
- `app/Http/Controllers/Admin/DashboardController.php`, `UserController.php`, `UserInvitationController.php`.
- `app/Http/Controllers/ChatController.php`, `MessageController.php`; `app/Http/Requests/Auth/*`, `Admin/*`, `StoreMessageRequest.php`.
- `app/Http/Middleware/EnsureUserIsActive.php`, `HandleInertiaRequests.php`; `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`.
- `app/Services/MessageService.php`, `app/Events/MessageCreated.php`, `app/Http/Resources/MessageResource.php`.
- `app/Notifications/UserInvitationNotification.php`, `app/Console/Commands/CreateAdmin.php`.
- `routes/web.php`, `routes/channels.php`; `config/chat.php`, `broadcasting.php`, `reverb.php`, `resend.php`, `.env.example`.
- `resources/js/pages/Auth/*`, `Admin/*`, `Chat/Index.vue`, `Welcome.vue`.
- `resources/js/components/AuthShell.vue`, `FormField.vue`, `layouts/AppLayout.vue`, `lib/http.ts`, `lib/realtime.ts`, `types/auth.ts`, `types/chat.ts`, `types/global.d.ts`.
- `tests/Feature/Auth/AuthenticationTest.php`, `Admin/UserManagementTest.php`, `MessageTest.php`, `InvitationChatFlowTest.php`, `phpunit.xml`.
- `composer.json`, `composer.lock`, `package.json`, `package-lock.json`.
