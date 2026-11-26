<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Tests\Functional;

use Mautic\CoreBundle\Helper\ThemeHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The whole path through Mautic: button, form, theme on disk, and the theme loading in the builder.
 * Themes are written to the real themes folder, under a random name, and removed afterwards.
 */
final class ConvertFunctionalTest extends MauticMysqlTestCase
{
    private const MJML = '<mjml><mj-body><mj-section><mj-column>'
        .'<mj-image src="/themes/brienz/assets/sign.jpeg" alt="Sign"/>'
        .'<mj-text>Hello {contactfield=firstname|there}, {{ literal }}</mj-text>'
        .'</mj-column></mj-section></mj-body></mjml>';

    private string $name;

    private string $key;

    private bool $backupDirExisted;

    protected function setUp(): void
    {
        parent::setUp();
        $this->name = 'ZZ E2T Test '.bin2hex(random_bytes(4));
        $this->key  = strtolower(str_replace(' ', '-', $this->name));
        $this->enableGrapesJs();
        $this->backupDirExisted = is_dir($this->backupDir());
    }

    protected function beforeTearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->themesDir().'/'.$this->key);
        $fs->remove(glob($this->backupDir().'/'.$this->key.'-*.zip') ?: []);
        // Tests usually run as root: a backup folder they create would be unwritable for the web server.
        if (!$this->backupDirExisted && is_dir($this->backupDir())) {
            $fs->remove($this->backupDir());
        }
    }

    public function testCreatesAThemeThatTheBuilderCanLoad(): void
    {
        $email = $this->email(self::MJML);

        $crawler = $this->client->request('GET', '/s/emails/view/'.$email->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/s/email-to-theme/'.$email->getId().'"]'), 'the email page offers "Save as theme"');

        $crawler = $this->client->request('GET', '/s/email-to-theme/'.$email->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name=email_to_theme]')->form();
        $form['email_to_theme[mode]']->select('new');
        $form['email_to_theme[name]']->setValue($this->name);
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('created with 1 image', (string) $this->client->getResponse()->getContent());

        $dir = $this->themesDir().'/'.$this->key;
        self::assertFileExists($dir.'/assets/sign.jpg');
        self::assertFileEquals($this->themesDir().'/brienz/assets/sign.jpeg', $dir.'/assets/sign.jpg');
        self::assertFileExists($dir.'/thumbnail.png');

        // Rendered the way the GrapesJS builder loads a theme (sandboxed Twig).
        $html = self::getContainer()->get(ThemeHelper::class)->renderThemeTemplate('@themes/'.$this->key.'/html/email.html.twig', ['template' => $this->key]);
        self::assertStringStartsWith('<mjml>', $html);
        self::assertStringContainsString('Hello {contactfield=firstname|there}, {{ literal }}', $html);
        self::assertMatchesRegularExpression('#src="https?://[^"]+/themes/'.preg_quote($this->key, '#').'/assets/sign\.jpg(\?[^"]*)?"#', $html);

        // And it is offered when creating an email.
        $installed = self::getContainer()->get(ThemeHelper::class)->getInstalledThemes('email', false, true);
        self::assertSame($this->name, $installed[$this->key] ?? null);
    }

    public function testUpdatesAnExistingThemeWithABackupAndThumbnail(): void
    {
        $fs  = new Filesystem();
        $dir = $this->themesDir().'/'.$this->key;
        $fs->dumpFile($dir.'/config.json', (string) json_encode(['name' => $this->name, 'builder' => ['grapesjsbuilder'], 'features' => ['email']]));
        $fs->dumpFile($dir.'/html/email.html.twig', '<mjml><mj-body></mj-body></mjml>');

        $email  = $this->email(self::MJML);
        $upload = sys_get_temp_dir().'/e2t-thumb-'.bin2hex(random_bytes(4)).'.png';
        imagepng(imagecreatetruecolor(300, 200), $upload);

        $crawler = $this->client->request('GET', '/s/email-to-theme/'.$email->getId());
        $form    = $crawler->filter('form[name=email_to_theme]')->form();
        $form['email_to_theme[mode]']->select('update');
        $form['email_to_theme[target]']->select($this->key);
        $form['email_to_theme[thumbnail]']->upload($upload);
        $this->client->submit($form);
        @unlink($upload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('updated with 1 image', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Hello {contactfield=firstname|there}', (string) file_get_contents($dir.'/html/email.html.twig'));
        self::assertSame([300, 200], array_slice((array) getimagesize($dir.'/thumbnail.png'), 0, 2));

        $backups = glob(self::getContainer()->getParameter('kernel.project_dir').'/var/email-to-theme-backups/'.$this->key.'-*.zip') ?: [];
        self::assertCount(1, $backups);
        $zip = new \ZipArchive();
        $zip->open($backups[0]);
        self::assertSame('<mjml><mj-body></mj-body></mjml>', $zip->getFromName($this->key.'/html/email.html.twig'));
    }

    public function testStockThemesAreNotOfferedForUpdate(): void
    {
        $crawler = $this->client->request('GET', '/s/email-to-theme/'.$this->email(self::MJML)->getId());

        $options = $crawler->filter('select[name="email_to_theme[target]"] option')->extract(['value']);
        self::assertNotContains('brienz', $options);
    }

    public function testEmailsWithoutMjmlCannotBeConverted(): void
    {
        $email = $this->email(null);

        $crawler = $this->client->request('GET', '/s/emails/view/'.$email->getId());
        self::assertCount(0, $crawler->filter('a[href$="/s/email-to-theme/'.$email->getId().'"]'));

        $this->client->request('GET', '/s/email-to-theme/'.$email->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cannot be saved as a theme', (string) $this->client->getResponse()->getContent());
    }

    public function testTheNameMustBeFreeAndPresent(): void
    {
        (new Filesystem())->mkdir($this->themesDir().'/'.$this->key);
        $email = $this->email(self::MJML);

        $crawler = $this->client->request('GET', '/s/email-to-theme/'.$email->getId());
        $form    = $crawler->filter('form[name=email_to_theme]')->form();
        $form['email_to_theme[name]']->setValue($this->name);
        $crawler = $this->client->submit($form);
        self::assertStringContainsString('already exists', $crawler->filter('form[name=email_to_theme]')->text());

        $form['email_to_theme[name]']->setValue('  ');
        $crawler = $this->client->submit($form);
        self::assertStringContainsString('Enter a name for the theme', $crawler->filter('form[name=email_to_theme]')->text());
    }

    private function email(?string $mjml): Email
    {
        $email = new Email();
        $email->setName('Source '.$this->name);
        $email->setSubject('Subject');
        $email->setTemplate('blank');
        $email->setEmailType('template');
        $email->setCustomHtml('<html><body>Hello</body></html>');
        $this->em->persist($email);

        if (null !== $mjml) {
            $builder = new GrapesJsBuilder();
            $builder->setEmail($email);
            $builder->setCustomMjml($mjml);
            $this->em->persist($builder);
        }
        $this->em->flush();

        return $email;
    }

    private function enableGrapesJs(): void
    {
        $plugin = new Plugin();
        $plugin->setName('GrapesJS Builder');
        $plugin->setBundle('GrapesJsBuilderBundle');
        $this->em->persist($plugin);

        $integration = new Integration();
        $integration->setPlugin($plugin);
        $integration->setIsPublished(true);
        $integration->setName('grapesjsbuilder');
        $this->em->persist($integration);
        $this->em->flush();
    }

    private function backupDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir').'/var/email-to-theme-backups';
    }

    private function themesDir(): string
    {
        return self::getContainer()->get('mautic.helper.paths')->getSystemPath('themes', true);
    }
}
