@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold mb-6">Gizlilik Politikası</h1>

    <p class="mb-4 text-gray-700">Son güncelleme: {{ now()->format('d.m.Y') }}</p>

    <div class="prose prose-gray max-w-none">
        <h2>1. Giriş</h2>
        <p>
            PostTimer ("biz", "uygulama"), kullanıcılarının Instagram profesyonel hesaplarından
            içerik yayınlamalarını ve yönetmelerini sağlayan bir platformdur. Bu gizlilik politikası,
            uygulamamızın hangi verileri topladığını, nasıl kullandığını ve koruduğunu açıklar.
        </p>

        <h2>2. Toplanan Veriler</h2>
        <p>Uygulamamız şu verileri işler:</p>
        <ul>
            <li><strong>Instagram hesap bilgileri:</strong> Kullanıcı adı, profil fotoğrafı, takipçi sayısı, medya sayısı (Instagram Graph API üzerinden)</li>
            <li><strong>Erişim jetonları:</strong> Instagram Business Login akışı ile alınan, şifrelenmiş olarak saklanan access token'lar</li>
            <li><strong>Gönderi içerikleri:</strong> Yayınlamak istediğiniz medya URL'leri, açıklamalar ve zamanlama bilgileri</li>
            <li><strong>Hesap kimliği:</strong> Instagram App-scoped user ID</li>
        </ul>

        <h2>3. Verilerin Kullanımı</h2>
        <p>Toplanan veriler yalnızca şu amaçlarla kullanılır:</p>
        <ul>
            <li>Instagram hesabınıza içerik yayınlamak</li>
            <li>Yayınlanmış içeriğinizi listelemek ve yönetmek</li>
            <li>Zamanlanmış gönderileri planlanan tarihte otomatik yayınlamak</li>
            <li>Hesap profil bilgilerinizi senkronize etmek</li>
        </ul>

        <h2>4. Veri Saklama</h2>
        <p>
            Erişim jetonları veritabanında şifrelenmiş olarak saklanır. Gönderi verileri
            yayın tamamlandııktan sonra da hesabınızda tutulmaya devam eder. Hesabınızı
            uygulamadan kaldırdığınızda, tüm ilgili veriler silinir.
        </p>

        <h2>5. Üçüncü Taraflar</h2>
        <p>
            Veriler yalnızca Meta Platforms (Instagram Graph API) ile paylaşılır.
            Hiçbir veri üçüncü taraf reklam ağlarına satılmaz veya paylaşılmaz.
        </p>

        <h2>6. Kullanıcı Hakları</h2>
        <p>Kullanıcılar şu haklara sahiptir:</p>
        <ul>
            <li>Verilerine erişim talep etme</li>
            <li>Verilerinin düzeltilmesini isteme</li>
            <li>Verilerinin silinmesini talep etme (aşağıya bakın)</li>
            <li>Uygulama erişimini iptal etme</li>
        </ul>

        <h2>7. Veri Silme</h2>
        <p>
            Verilerinizin silinmesini talep etmek için
            <a href="mailto:yunusemregurlu@gmail.com">yunusemregurlu@gmail.com</a>
            adresine e-posta gönderebilir veya uygulama içinden hesabınızı kaldırabilirsiniz.
            Talebiniz 30 gün içinde işlenir. Detaylı talimatlar için
            <a href="{{ route('data-deletion') }}">Veri Silme Talimatları</a> sayfasına bakın.
        </p>

        <h2>8. Güvenlik</h2>
        <p>
            Erişim jetonları AES-256 ile şifrelenerek saklanır. Tüm iletişim HTTPS üzerinden yapılır.
            Instagram kullanıcı adı ve şifreleri bizim tarafımızda saklanmaz; kimlik doğrulama
            doğrudan Instagram tarafından yapılır.
        </p>

        <h2>9. İletişim</h2>
        <p>
            Gizlilik politikası ile ilgili sorularınız için:
            <a href="mailto:yunusemregurlu@gmail.com">yunusemregurlu@gmail.com</a>
        </p>
    </div>
</div>
@endsection
