<?php

use App\Services\Moderation\CompositeProfanityEngine;
use App\Services\Moderation\DictionaryProfanityEngine;
use App\Services\Moderation\TerlikProfanityEngine;
use Terlik\Terlik;

test('supplemental dictionary blocks real package regressions that Terlik allows', function (string $text) {
    $terlik = new TerlikProfanityEngine(new Terlik);
    $dictionary = new DictionaryProfanityEngine(dirname(__DIR__, 3).'/resources/moderation/profanity-extra.json');
    $engine = new CompositeProfanityEngine($terlik, $dictionary);

    expect($terlik->check($text)->allowed)->toBeTrue();
    $result = $engine->check($text);

    expect($result->allowed)->toBeFalse();
    expect($result->category)->toBe('profanity');
    expect($result->reason)->toBe('profanity_detected');
})->with([
    'ProfanityTest amk' => 'amk',
    'ProfanityTest amq' => 'amq',
    'ProfanityTest compound koyim' => 'aminakoyim',
    'ProfanityTest compound koydugum' => 'aminakoydugum',
    'EdgeCasesTest compound koyayim' => 'aminakoyayim',
    'EdgeCasesTest compound koydum' => 'aminakoydum',
    'EdgeCasesTest compound koydugumun' => 'aminakoydugumun',
    'SuffixTest present tense' => 'sikerim',
    'ProfanityTest future tense' => 'sikicem',
    'ProfanityTest past tense' => 'siktim',
    'ProfanityTest optative' => 'sikeyim',
    'ProfanityTest adjective' => 'sikik',
    'ProfanityTest possessive' => 'sikim',
    'SuffixTest plural past' => 'siktiler',
    'SuffixTest plural' => 'siktirler',
    'AdversarialAuditTest plural' => 'orospular',
    'SuffixTest suffix chain' => 'orospuluklar',
    'SuffixTest gavatlar' => 'gavatlar',
    'SuffixTest kahpeler' => 'kahpeler',
    'SuffixTest pezevenkler' => 'pezevenkler',
    'SuffixTest yavsaklik' => 'yavsaklik',
    'SuffixTest pustlar' => 'pustlar',
    'ProfanityTest root' => 'bok',
    'SuffixTest derivation' => 'boktan',
    'SuffixTest leet suffix' => '$1kt1rler',
    'EdgeCasesTest leet root' => '8ok',
]);

test('dictionary normalization handles unicode separators and whitespace without substring matching', function (string $text) {
    $engine = new DictionaryProfanityEngine(dirname(__DIR__, 3).'/resources/moderation/profanity-extra.json');

    expect($engine->check($text)->blocked())->toBeTrue();
})->with([
    'Turkish uppercase' => 'SİKTİRLER',
    'ASCII uppercase' => 'SIKTIRLER',
    'Turkish diacritics' => 'yavşaklık',
    'decomposed Unicode' => "yavs\u{0327}aklık",
    'fullwidth Unicode' => 'ｓｉｋｔｉｒｌｅｒ',
    'dots' => 's.i.k.t.i.r.l.e.r',
    'dashes and leet' => '$1k-t1rler',
    'punctuation around words' => 'Merhaba,siktirler!',
    'whitespace' => "  Merhaba\n\t siktirler\u{00A0} ",
]);

test('dictionary allows similar innocent words and preserves whitespace boundaries', function (string $text) {
    $engine = new DictionaryProfanityEngine(dirname(__DIR__, 3).'/resources/moderation/profanity-extra.json');

    expect($engine->check($text)->allowed)->toBeTrue();
})->with([
    'Turkish message' => 'Merhaba, toplantı saat kaçta başlayacak?',
    'distinct dotless vowels' => 'Limonu sıktım. Son sıkım tamamlandı.',
    'package whitelist' => 'sıkıntı sıkma sıkı sikke Amsterdam boksör bokser malzeme dolunay',
    'embedded suffix' => 'eksiktirler',
    'embedded acronym' => 'tamkatalog',
    'Norwegian language' => 'Bokmål',
    'alphanumeric code' => 'AMK123',
    'separate words' => 'bo k',
    'empty' => '',
    'insults outside fallback scope' => 'salaksin aptallarin',
]);

test('Terlik blocks short circuit before the supplemental dictionary is opened', function () {
    $engine = new CompositeProfanityEngine(
        new TerlikProfanityEngine(new Terlik),
        new DictionaryProfanityEngine('/dictionary-must-not-be-opened.json'),
    );

    expect($engine->check('siktir git')->blocked())->toBeTrue();
});

test('the same engine keeps the loaded dictionary in memory across checks', function () {
    $path = tempnam(sys_get_temp_dir(), 'chat-profanity-');

    try {
        file_put_contents($path, '["siktirler"]');
        $engine = new DictionaryProfanityEngine($path);
        expect($engine->check('siktirler')->blocked())->toBeTrue();

        file_put_contents($path, '[]');

        expect($engine->check('siktirler')->blocked())->toBeTrue();
    } finally {
        unlink($path);
    }
});
