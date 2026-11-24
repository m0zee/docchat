<?php

it('shows the landing page to guests', function () {
    $this->get(route('home'))->assertOk();
});
