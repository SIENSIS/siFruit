<?php

declare(strict_types=1);

namespace Modules\Contact\Tests;

use Modules\Contact\Sections\ContactFormSection;
use Modules\Contact\Services\FormToken;
use Modules\Core\Sections\RenderContext;
use Modules\Core\Sections\SectionData;
use Modules\Core\Sections\SectionRegistry;

/**
 * The section `contact.form`: registered from the manifest, it shows only the recipients it
 * offers (none chosen = every active one) and signs them, with the page's path, in its token.
 *
 * @internal
 */
final class ContactSectionTest extends ContactTestCase
{
    public function testTheTypeIsRegistered(): void
    {
        $this->assertInstanceOf(ContactFormSection::class, (new SectionRegistry())->get('contact.form'));
    }

    public function testTheSectionOffersItsRecipientsAndSignsThem(): void
    {
        $office = $this->recipient(['ca' => 'Secretaria'], 'secretaria@example.com');
        $this->recipient(['ca' => 'Direcció']);
        $type    = new ContactFormSection();
        $context = new RenderContext('ca', 'ca');

        $html = $type->render(new SectionData('contact.form', ['recipients' => [(string) $office]], ['ca' => ['heading' => 'Escriu-nos', 'intro' => '']], [], false, 7), $context);
        $this->assertStringContainsString('Escriu-nos', $html);
        $this->assertStringContainsString('Secretaria', $html);
        $this->assertStringNotContainsString('Direcció', $html);
        $this->assertStringNotContainsString('secretaria@example.com', $html);
        $this->assertStringContainsString('id="contact-7-form"', $html);

        preg_match('/name="kp_token" value="([^"]+)"/', $html, $match);
        $token = FormToken::verify(html_entity_decode($match[1] ?? ''), time() + 10);
        $this->assertTrue($token['ok']);
        $this->assertSame([$office], $token['recipients']);

        $every = $type->render(new SectionData('contact.form', ['recipients' => []], [], [], false, 8), $context);
        $this->assertStringContainsString('Secretaria', $every);
        $this->assertStringContainsString('Direcció', $every);
    }

    public function testASingleTopicIsShownAsFixedText(): void
    {
        $outing = $this->recipient(['ca' => 'Sortida al Montseny']);
        $this->recipient(['ca' => 'Informació general']);

        $html = (new ContactFormSection())->render(new SectionData('contact.form', ['recipients' => [(string) $outing]], [], [], false, 9), new RenderContext('ca', 'ca'));

        $this->assertStringContainsString('value="Sortida al Montseny"', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('<input type="hidden" name="recipient" value="' . $outing . '">', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('Informació general', $html);
    }

    public function testARefusedPostOfASectionGoesBackToItsPageWithTheErrors(): void
    {
        $outing = $this->recipient(['ca' => 'Sortida al Montseny']);
        $post   = $this->message($outing, ['kp_token' => $this->token([$outing], '/sortida-montseny'), 'kp_form' => 'contact-9', 'privacy' => '']);

        $response = $this->send('POST', 'contact', $post);
        $response->assertRedirect();
        $this->assertStringEndsWith('/sortida-montseny#contact-9-form', $response->getRedirectUrl() ?? '');
        $this->assertSame(0, $this->messageCount());

        // The page that holds the section shows the values and the errors.
        session()->setFlashdata(\Modules\Contact\Services\ContactForm::FLASH, [
            'prefix' => 'contact-9', 'old' => ['subject' => 'Autocar de tornada'], 'errors' => ['privacy' => $this->ca('Contact.form.privacyRequired')], 'alert' => null, 'captcha' => false,
        ]);
        $html = html_entity_decode((new ContactFormSection())->render(new SectionData('contact.form', ['recipients' => [(string) $outing]], [], [], false, 9), new RenderContext('ca', 'ca')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Autocar de tornada', $html);
        $this->assertStringContainsString($this->ca('Contact.form.privacyRequired'), $html);

        // Another section on the same page does not take them.
        session()->setFlashdata(\Modules\Contact\Services\ContactForm::FLASH, ['prefix' => 'contact-9', 'old' => ['subject' => 'Autocar de tornada'], 'errors' => [], 'alert' => null, 'captcha' => false]);
        $other = (new ContactFormSection())->render(new SectionData('contact.form', ['recipients' => []], [], [], false, 10), new RenderContext('ca', 'ca'));
        $this->assertStringNotContainsString('Autocar de tornada', $other);
    }

    public function testAForgedFormIdStaysOnTheContactPage(): void
    {
        $outing = $this->recipient(['ca' => 'Sortida al Montseny']);
        $post   = $this->message($outing, ['kp_token' => $this->token([$outing], '/sortida-montseny'), 'kp_form' => 'x"><script>', 'privacy' => '']);

        $page = $this->body('POST', 'contact', $post);
        $this->assertStringContainsString($this->ca('Contact.form.privacyRequired'), $page);
        $this->assertStringNotContainsString('<script>"', $page);
    }
}
