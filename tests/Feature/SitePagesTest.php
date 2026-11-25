<?php

use App\Models\User;
use App\Support\Docs;

it('shows the landing page with the main sections', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Ask your documents. Get answers with sources.')
        ->assertSee('id="features"', false)
        ->assertSee('You choose where the AI runs')
        ->assertSee(route('pricing'))
        ->assertSee(route('docs.show'));
});

it('shows the pricing page with free plans only', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertSee('Simple pricing: free')
        ->assertSee('Bring your own key')
        ->assertSee('Self-host')
        ->assertSee('20 questions a minute per person')
        ->assertDontSee('$')
        ->assertDontSee('/month');
});

it('renders every docs page with the sidebar and next link', function () {
    foreach (array_keys(Docs::all()) as $slug) {
        $response = $this->get(route('docs.show', $slug === 'index' ? null : $slug))->assertOk();
        $response->assertSee(Docs::title('ai-keys'));
    }

    $this->get(route('docs.show', 'getting-started'))
        ->assertSee('Create an account')
        ->assertSee(route('docs.show', 'documents'));
});

it('returns 404 for unknown docs pages and leaves the API reference alone', function () {
    $this->get('/docs/nope')->assertNotFound();
    $this->get('/docs/../.env')->assertNotFound();

    expect(route('docs.show', 'api-guide'))->toEndWith('/docs/api-guide')
        ->and(app('router')->getRoutes()->match(request()->create('/docs/api'))->getName())->not->toBe('docs.show');
});

it('strips raw HTML from docs markdown', function () {
    expect(Docs::html('index'))->not->toContain('<script');
});

it('links internal docs pages that exist', function () {
    foreach (array_keys(Docs::all()) as $slug) {
        preg_match_all('#\]\(/docs/([a-z0-9-]+)\)#', (string) file_get_contents(Docs::path($slug)), $links);

        foreach ($links[1] as $target) {
            expect($target === 'api' || Docs::exists($target))->toBeTrue("{$slug}.md links to missing /docs/{$target}");
        }
    }
});

it('shows the landing page to signed-in users with a way into the app', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee('Open DocChat');
});
