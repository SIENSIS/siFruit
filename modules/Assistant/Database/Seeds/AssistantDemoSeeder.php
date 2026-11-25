<?php

declare(strict_types=1);

namespace Modules\Assistant\Database\Seeds;

use CodeIgniter\I18n\Time;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Demo\DemoData;
use Modules\Core\Demo\DemoSeeder;
use Modules\Core\Security\HtmlSanitizer;

/**
 * Demo FAQs of the Assistant module (`php spark modules:demo Assistant`): four public questions
 * and one for signed-in users, in the shipped languages the site offers. The Core rebuilds the
 * module's search source afterwards. The bubble itself stays off until `Assistant.enabled`.
 */
final class AssistantDemoSeeder extends DemoSeeder
{
    public function load(DemoData $demo): void
    {
        $now = Time::now()->toDateTimeString();

        foreach (self::faqs() as $position => $faq) {
            $id = (int) $demo->insert('assistant_faqs', [
                'visibility' => $faq['visibility'],
                'link'       => $faq['link'],
                'active'     => 1,
                'position'   => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($faq['texts'] as $locale => [$question, $answer, $keywords, $label]) {
                if (! in_array($locale, ContentTranslations::codes(), true)) {
                    continue;
                }

                $demo->insert('assistant_faq_translations', [
                    'faq_id'     => $id,
                    'locale'     => $locale,
                    'question'   => $question,
                    'answer'     => HtmlSanitizer::clean($answer),
                    'keywords'   => $keywords,
                    'link_label' => $label,
                ]);
            }
        }
    }

    /**
     * @return list<array{visibility: string, link: string|null, texts: array<string, array{0: string, 1: string, 2: string, 3: string|null}>}>
     */
    private static function faqs(): array
    {
        return [
            [
                'visibility' => 'public',
                'link'       => '/contact',
                'texts'      => [
                    'ca' => ['Quin és l\'horari d\'atenció?', '<p>Atenem de <strong>dilluns a divendres de 9 a 14 h</strong> i els dimarts i dijous també de 16 a 18 h.</p>', "a quina hora obriu\nhores\nobert\ntancat\nhorari secretaria", 'Contacteu-nos'],
                    'es' => ['¿Cuál es el horario de atención?', '<p>Atendemos de <strong>lunes a viernes de 9 a 14 h</strong> y los martes y jueves también de 16 a 18 h.</p>', "a qué hora abrís\nhoras\nabierto\ncerrado\nhorario secretaría", 'Contáctanos'],
                    'en' => ['What are your opening hours?', '<p>We are open <strong>Monday to Friday from 9 am to 2 pm</strong>, and on Tuesdays and Thursdays also from 4 to 6 pm.</p>', "when are you open\nhours\nopen\nclosed\noffice hours", 'Contact us'],
                ],
            ],
            [
                'visibility' => 'public',
                'link'       => null,
                'texts'      => [
                    'ca' => ['Com em puc inscriure a una activitat?', '<p>Trieu l\'activitat al calendari, obriu-la i feu clic a <em>Inscriu-me</em>. Rebreu la confirmació per correu electrònic.</p>', "inscripció\nmatrícula\napuntar-se\nreserva\nplaça", null],
                    'es' => ['¿Cómo me puedo inscribir en una actividad?', '<p>Elige la actividad en el calendario, ábrela y haz clic en <em>Inscribirme</em>. Recibirás la confirmación por correo electrónico.</p>', "inscripción\nmatrícula\napuntarse\nreserva\nplaza", null],
                    'en' => ['How do I sign up for an activity?', '<p>Choose the activity in the calendar, open it and click <em>Sign me up</em>. You will get a confirmation by email.</p>', "registration\nenrol\nbook\nplace\nsign up", null],
                ],
            ],
            [
                'visibility' => 'public',
                'link'       => '/qui-som',
                'texts'      => [
                    'ca' => ['On sou?', '<p>Som al carrer Major, 1, a tocar de la plaça. Hi podeu arribar amb autobús (línies 3 i 7).</p>', "adreça\ncom arribar\nubicació\nmapa\non és", 'Qui som'],
                    'es' => ['¿Dónde estáis?', '<p>Estamos en la calle Mayor, 1, junto a la plaza. Podéis llegar en autobús (líneas 3 y 7).</p>', "dirección\ncómo llegar\nubicación\nmapa\ndónde está", 'Quiénes somos'],
                    'en' => ['Where are you?', '<p>We are at 1 Main Street, next to the square. You can come by bus (lines 3 and 7).</p>', "address\nhow to get there\nlocation\nmap", 'About us'],
                ],
            ],
            [
                'visibility' => 'public',
                'link'       => null,
                'texts'      => [
                    'ca' => ['He oblidat la contrasenya, què faig?', '<p>A la pàgina d\'entrada feu clic a <em>He oblidat la contrasenya</em> i seguiu l\'enllaç que rebreu per correu.</p>', "contrasenya\nclau\nno puc entrar\naccés\nrecuperar", null],
                    'es' => ['He olvidado la contraseña, ¿qué hago?', '<p>En la página de acceso haz clic en <em>He olvidado la contraseña</em> y sigue el enlace que recibirás por correo.</p>', "contraseña\nclave\nno puedo entrar\nacceso\nrecuperar", null],
                    'en' => ['I forgot my password, what can I do?', '<p>On the sign-in page click <em>Forgot your password</em> and follow the link you will get by email.</p>', "password\ncannot sign in\naccess\nreset", null],
                ],
            ],
            [
                'visibility' => 'private',
                'link'       => null,
                'texts'      => [
                    'ca' => ['On trobo els documents interns?', '<p>Al back-office, a <em>Fitxers</em> › <em>Compartits</em>. Només els veuen els usuaris que han entrat.</p>', "documents\nfitxers\nintranet\nactes\nplantilles", null],
                    'es' => ['¿Dónde encuentro los documentos internos?', '<p>En el back-office, en <em>Archivos</em> › <em>Compartidos</em>. Solo los ven los usuarios que han entrado.</p>', "documentos\narchivos\nintranet\nactas\nplantillas", null],
                    'en' => ['Where are the internal documents?', '<p>In the back office, under <em>Files</em> › <em>Shared</em>. Only signed-in users see them.</p>', "documents\nfiles\nintranet\nminutes\ntemplates", null],
                ],
            ],
        ];
    }
}
