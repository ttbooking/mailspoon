<?php

test('the application has no public web routes', function () {
    $response = $this->get('/');

    $response->assertNotFound();
});
