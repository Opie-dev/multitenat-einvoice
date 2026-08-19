<?php

it('reports healthy', function () {
    $this->getJson('/v1/health')->assertOk()->assertJson(['status' => 'ok']);
});
