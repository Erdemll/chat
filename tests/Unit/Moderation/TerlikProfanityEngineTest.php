<?php

use App\Services\Moderation\TerlikProfanityEngine;
use Terlik\Terlik;

test('clean text and package whitelisted words are allowed', function (string $text) {
    $engine = new TerlikProfanityEngine(new Terlik);

    $result = $engine->check($text);

    expect($result->allowed)->toBeTrue();
    expect($result->blocked())->toBeFalse();
    expect($result->category)->toBeNull();
    expect($result->reason)->toBeNull();
})->with([
    'normal message' => 'merhaba dunya',
    'whitelisted word' => 'sıkıntı',
]);

test('documented profanity and supported evasions are blocked without exposing matches', function (string $text) {
    $engine = new TerlikProfanityEngine(new Terlik);

    $result = $engine->check($text);

    expect($result->allowed)->toBeFalse();
    expect($result->blocked())->toBeTrue();
    expect($result->category)->toBe('profanity');
    expect($result->reason)->toBe('profanity_detected');
})->with([
    'plain (TerlikTest)' => 'siktir git',
    'leet (EdgeCasesTest)' => '$1kt1r',
    'separators (EdgeCasesTest)' => 's.i.k.t.i.r',
    'repeated letters (DetectorTest)' => 'siiiktir',
    'suffix (SuffixTest)' => 'orospuluk',
    'separators with suffix (SuffixTest)' => 's.i.k.t.i.r.l.e.r',
]);
