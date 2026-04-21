<?php

test('light mode is the default appearance when no preference is stored', function () {
    $head = view('partials.head')->render();

    expect($head)->toContain("window.localStorage.setItem('flux.appearance', 'light')");
});
