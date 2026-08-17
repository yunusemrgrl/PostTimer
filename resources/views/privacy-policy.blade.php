<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gizlilik Politikası — PostTimer</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="max-w-3xl mx-auto px-4 py-12">
        <h1 class="text-3xl font-bold mb-6">Gizlilik Politikası</h1>

        <p class="mb-4 text-gray-600">Son güncelleme: {{ now()->format('d.m.Y') }}</p>

        <div class="space-y-6 leading-relaxed">
            <section>
                <h2 class="text-xl font-semibold mb-2">1. Giriş</h2>
                <p>
                    PostTimer ("biz", "uygulama"), kullanıcılarının Instagram profesyonel hesaplarından
                    içerik yayınlamalarını ve yönetmelerini sağlayan bir platformdur. Bu gizlilik politikası,
                    uygulamamızın hangi verileri topladığını, nasıl kullandığını ve koruduğunu açıklar.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">2. Toplanan Veriler</h2>
                <p>Uygulamamız şu verileri işler:</p>
                <ul class="list-disc list-inside mt-2 space-y-1">
                    <li><strong>Instagram hesap bilgileri:</strong> Kullanıcı adı, profil fotoğrafı, takipçi sayısı, medya sayısı (Instagram Graph API üzerinden)</li>
                    <li><strong>Erişim jetonları:</strong> Instagram Business Login akışı ile alınan, şifrelenmiş olarak saklanan access token'lar</li>
                    <li><strong>Gönderi içerikleri:</strong> Yayınlamak istediğiniz medya URL'leri, açıklamalar ve zamanlama bilgileri</li>
                    <li><strong>Hesap kimliği:</strong> Instagram App-scoped user ID</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">3. Verilerin Kullanımı</h2>
                <p>Toplanan veriler yalnızca şu amaçlarla kullanılır:</p>
                <ul class="list-disc list-inside mt-2 space-y-1">
                    <li>Instagram hesabınıza içerik yayınlamak</li>
                    <li>Yayınlanmış içeriğinizi listelemek ve yönetmek</li>
                    <li>Zamanlanmış gönderileri planlanan tarihte otomatik yayınlamak</li>
                    <li>Hesap profil bilgilerinizi senkronize etmek</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">4. Veri Saklama</h2>
                <p>
                    Erişim jetonları veritabanında şifrelenmiş olarak saklanır. Gönderi verileri
                    yayın tamamlandıktan sonra da hesabınızda tutulmaya devam eder. Hesabınızı
                    uygulamadan kaldırdığınızda, tüm ilgili veriler silinir.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">5. Üçüncü Taraflar</h2>
                <p>
                    Veriler yalnızca Meta Platforms (Instagram Graph API) ile paylaşılır.
                    Hiçbir veri üçüncü taraf reklam ağlarına satılmaz veya paylaşılmaz.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">6. Kullanıcı Hakları</h2>
                <p>Kullanıcılar şu haklara sahiptir:</p>
                <ul class="list-disc list-inside mt-2 space-y-1">
                    <li>Verilerine erişim talep etme</li>
                    <li>Verilerinin düzeltilmesini isteme</li>
                    <li>Verilerinin silinmesini talep etme</li>
                    <li>Uygulama erişimini iptal etme</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">7. Veri Silme</h2>
                <p>
                    Verilerinizin silinmesini talep etmek için
                    <a href="mailto:yunusemregurlu@gmail.com" class="text-blue-600 underline">yunusemregurlu@gmail.com</a>
                    adresine e-posta gönderebilir veya uygulama içinden hesabınızı kaldırabilirsiniz.
                    Talebiniz 30 gün içinde işlenir. Detaylı talimatlar için
                    <a href="/data-deletion" class="text-blue-600 underline">Veri Silme Talimatları</a> sayfasına bakın.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">8. Güvenlik</h2>
                <p>
                    Erişim jetonları AES-256 ile şifrelenerek saklanır. Tüm iletişim HTTPS üzerinden yapılır.
                    Instagram kullanıcı adı ve şifreleri bizim tarafımızda saklanmaz; kimlik doğrulama
                    doğrudan Instagram tarafından yapılır.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">9. İletişim</h2>
                <p>
                    Gizlilik politikası ile ilgili sorularınız için:
                    <a href="mailto:yunusemregurlu@gmail.com" class="text-blue-600 underline">yunusemregurlu@gmail.com</a>
                </p>
            </section>
        </div>
    </div>
</body>
</html>
