<?php

test('the application returns a successful response', function () {
    // Panel tabanlı uygulama: kök dizinde web rotası yok, panel giriş sayfası 200 döner.
    $response = $this->get('/admin/login');

    $response->assertStatus(200);
});
