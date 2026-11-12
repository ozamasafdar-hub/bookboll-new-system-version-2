<?php

/**
 * Image headers on WhatsApp marketing templates.
 *
 * Creating one means pushing the image through Meta's resumable upload API and
 * handing the returned handle to the template as its example; sending one means
 * a header parameter carrying the image link on EVERY message, or Meta rejects
 * the whole campaign. Both shapes are pinned here against a faked Graph API.
 */

use App\Services\WhatsappService;
use Illuminate\Support\Facades\Http;

function waSender(): array
{
    return ['owner_user_id' => null, 'is_provider' => false, 'phone_number_id' => '111', 'waba_id' => '222', 'token' => 'tok', 'token_source' => 'dashboard', 'enabled' => true];
}

function templatePayload(array $template, array $vars): array
{
    $m = new ReflectionMethod(WhatsappService::class, 'templatePayload');

    return $m->invoke(null, $template, $vars);
}

it('puts the image on the header and the variables on the body', function () {
    $p = templatePayload(['name' => 'promo', 'language' => 'en_US', 'headerImageUrl' => 'https://cdn.test/banner.jpg'], ['Ali']);

    expect($p['template']['components'])->toBe([
        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.test/banner.jpg']]]],
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ali']]],
    ]);
});

it('sends no components at all for a plain template', function () {
    expect(templatePayload(['name' => 'hello_world', 'language' => 'en_US'], []))->not->toHaveKey('components', 'template')
        ->and(templatePayload(['name' => 'hello_world', 'language' => 'en_US'], [])['template'])->not->toHaveKey('components');
});

it('uploads the image to Meta and submits an IMAGE header with its handle', function () {
    config(['services.whatsapp.app_id' => '999', 'services.whatsapp.graph_url' => 'https://graph.test/v25.0']);
    Http::fake([
        'cdn.test/*'                       => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
        'graph.test/v25.0/999/uploads*'    => Http::response(['id' => 'upload:abc'], 200),
        'graph.test/v25.0/upload:abc'      => Http::response(['h' => '4:handle'], 200),
        'graph.test/v25.0/222/message_templates' => Http::response(['id' => 't1', 'status' => 'PENDING'], 200),
    ]);

    $out = app(WhatsappService::class)->createTemplate(waSender(), [
        'name' => 'Summer Offer', 'language' => 'en_US', 'category' => 'MARKETING',
        'bodyText' => 'Hi {{1}}, 20% off this week.', 'headerText' => 'ignored when an image is set',
        'headerImageUrl' => 'https://cdn.test/banner.png', 'examples' => ['Ali'],
    ]);

    expect($out['ok'])->toBeTrue()->and($out['status'])->toBe('PENDING');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/999/uploads') && str_contains($req->url(), 'file_type=image%2Fpng') && str_contains($req->url(), 'file_length=8'));
    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/upload:abc') && $req->hasHeader('file_offset', '0') && $req->body() === 'PNGBYTES');
    Http::assertSent(function ($req) {
        if (! str_ends_with($req->url(), '/222/message_templates')) return false;
        $c = $req['components'];
        return $c[0] === ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4:handle']]]
            && $c[1]['type'] === 'BODY' && $c[1]['example'] === ['body_text' => [['Ali']]]
            && count($c) === 2;
    });
});

it('refuses an image that is not a public jpeg/png, without touching Meta', function () {
    config(['services.whatsapp.app_id' => '999']);
    Http::fake(['cdn.test/*' => Http::response('<svg/>', 200, ['Content-Type' => 'image/svg+xml'])]);

    $out = app(WhatsappService::class)->createTemplate(waSender(), ['name' => 'x', 'bodyText' => 'Hi', 'headerImageUrl' => 'https://cdn.test/a.svg']);

    expect($out['ok'])->toBeFalse()->and($out['error'])->toContain('JPEG or PNG');
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'graph'));
});

it('lists whether a template needs an image', function () {
    config(['services.whatsapp.graph_url' => 'https://graph.test/v25.0']);
    Http::fake(['graph.test/*' => Http::response(['data' => [
        ['name' => 'promo', 'status' => 'APPROVED', 'language' => 'en_US', 'category' => 'MARKETING', 'components' => [
            ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['https://scontent.test/sample.jpg']]],
            ['type' => 'BODY', 'text' => 'Hi {{1}}'],
        ]],
        ['name' => 'hello_world', 'status' => 'APPROVED', 'language' => 'en_US', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hello']]],
    ]], 200)]);

    $list = app(WhatsappService::class)->listTemplates(waSender())['templates'];

    expect($list[0])->toMatchArray(['headerFormat' => 'IMAGE', 'headerImage' => 'https://scontent.test/sample.jpg', 'varCount' => 1])
        ->and($list[1])->toMatchArray(['headerFormat' => '', 'headerImage' => '']);
});

it('puts a video on the template header without creating an image parameter',function(){
 $p=templatePayload(['name'=>'video_offer','headerVideoUrl'=>'https://cdn.test/court.mp4'],['Ali']);
 expect($p['template']['components'][0])->toBe(['type'=>'header','parameters'=>[['type'=>'video','video'=>['link'=>'https://cdn.test/court.mp4']]]]);
});
it('uploads and submits a VIDEO header to Meta',function(){
 config(['services.whatsapp.app_id'=>'999','services.whatsapp.graph_url'=>'https://graph.test/v25.0']);
 Http::fake(['cdn.test/*'=>Http::response('MP4BYTES',200,['Content-Type'=>'video/mp4']),'graph.test/v25.0/999/uploads*'=>Http::response(['id'=>'upload:video']),'graph.test/v25.0/upload:video'=>Http::response(['h'=>'video-handle']),'graph.test/v25.0/222/message_templates'=>Http::response(['id'=>'tpl-video','status'=>'PENDING'])]);
 expect(app(WhatsappService::class)->createTemplate(waSender(),['name'=>'video_offer','bodyText'=>'Book again','headerVideoUrl'=>'https://cdn.test/court.mp4'])['ok'])->toBeTrue();
 Http::assertSent(fn($req)=>str_ends_with($req->url(),'/message_templates') && $req['components'][0]['format']==='VIDEO');
});
it('rejects a mismatched video mime and competing image/video headers',function(){
 config(['services.whatsapp.app_id'=>'999']); Http::fake(['cdn.test/*'=>Http::response('PNG',200,['Content-Type'=>'image/png'])]);
 expect(app(WhatsappService::class)->createTemplate(waSender(),['name'=>'x','bodyText'=>'Hi','headerVideoUrl'=>'https://cdn.test/a.mp4'])['ok'])->toBeFalse();
 expect(app(WhatsappService::class)->createTemplate(waSender(),['name'=>'x','bodyText'=>'Hi','headerVideoUrl'=>'https://cdn.test/a.mp4','headerImageUrl'=>'https://cdn.test/a.png'])['ok'])->toBeFalse();
});
