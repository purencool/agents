<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Pnc;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the {@see \App\Console\Commands\Pnc\AIAgentSkillsShow} command.
 *
 * Verifies:
 *  - FAILURE when the skills config is empty.
 *  - FAILURE with a helpful tip when the skill key is not found.
 *  - SUCCESS and correct output when the skill exists.
 */
class AIAgentSkillsShowCommandTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // Failure: empty skills registry
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When config('pnc.skills') is empty, the command should error
     * and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenNoSkillsLoaded(): void
    {
        config()->set('pnc.skills', []);

        $this->artisan('pnc:agent:skills:show', ['skill' => 'any.skill'])
            ->expectsOutputToContain('No skills found')
            ->expectsOutputToContain('PncAgenticsServiceProvider')
            ->assertFailed();
    }

    /**
     * When config('pnc.skills') is null (not set), the command should
     * also fail gracefully.
     */
    #[Test]
    public function handle_failsWhenSkillsConfigIsNull(): void
    {
        config()->set('pnc.skills', null);

        $this->artisan('pnc:agent:skills:show', ['skill' => 'any.skill'])
            ->expectsOutputToContain('No skills found')
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Failure: skill not found
    // ─────────────<?php

    declare(strict_types=1);

    namespace Tests\Feature\Console\Commands\Pnc;

    use PHPUnit\Framework\Attributes\Test;
    use Tests\TestCase;

    /**
     * Feature tests for the {@see \App\Console\Commands\Pnc\AIAgentSkillsShow} command.
     *
     * Verifies:
     *  - FAILURE when the skills config is empty.
     *  - FAILURE with a helpful tip when the skill key is not found.
     *  - SUCCESS and correct output when the skill exists.
     */
    class AIAgentSkillsShowCommandTest extends TestCase
    {
        // ─────────────────────────────────────────────────────────────────────
        // Failure: empty skills registry
        // ─────────────────────────────────────────────────────────────────────

        /**
         * When config('pnc.skills') is empty, the command should error
         * and return FAILURE.
         */
        #[Test]
        public function handle_failsWhenNoSkillsLoaded(): void
        {
            config()->set('pnc.skills', []);

            $this->artisan('pnc:agent:skills:show', ['skill' => 'any.skill'])
                ->expectsOutputToContain('No skills found')
                ->expectsOutputToContain('PncAgenticsServiceProvider')
                ->assertFailed();
        }

        /**
         * When config('pnc.skills') is null (not set), the command should
         * also fail gracefully.
         */
        #[Test]
        public function handle_failsWhenSkillsConfigIsNull(): void
        {
            config()->set('pnc.skills', null);

            $this->artisan('pnc:agent:skills:show', ['skill' => 'any.skill'])
                ->expectsOutputToContain('No skills found')
                ->assertFailed();
        }

        // ─────────────────────────────────────────────────────────────────────
        // Failure: skill not found
        // ─────────────────────────────────────────────────────────────────────

        /**
         * When the requested skill key does not exist in the registry, the
         * command should error, show a tip, and return FAILURE.
         */
        #[Test]
        public function handle_failsWhenSkillNotFound(): void
        {
            config()->set('pnc.skills', [
                'known.skill' => '# Known Skill',
            ]);

            $this->artisan('pnc:agent:skills:show', ['skill' => 'unknown.skill'])
                ->expectsOutputToContain("Skill 'unknown.skill' not found.")
                ->expectsOutputToContain("Tip: Use 'php artisan agent:skills'")
                ->assertFailed();
        }

        // ─────────────────────────────────────────────────────────────────────
        // Success
        // ─────────────────────────────────────────────────────────────────────

        /**
         * When the skill exists, the command should print its markdown
         * content and return SUCCESS.
         */
        #[Test]
        public function handle_printsSkillContentOnSuccess(): void
        {
            $markdown = "# Hook Introduction Writer\n\nYou are a skilled writer who crafts compelling hooks.";

            config()->set('pnc.skills', [
                'developer_docs.core_writing.hook_introduction_writer' => $markdown,
            ]);

            $this->artisan('pnc:agent:skills:show', [
                'skill' => 'developer_docs.core_writing.hook_introduction_writer',
            ])
                ->expectsOutputToContain('Hook Introduction Writer')
                ->expectsOutputToContain('crafts compelling hooks')
                ->assertSuccessful();
        }

        /**
         * The full markdown content (including newlines) should be printed
         * as-is without modification.
         */
        #[Test]
        public function handle_printsFullContentUnmodified(): void
        {
            $content = "# Title\n\n## Section\n\n- Item one\n- Item two\n\n> Blockquote text";

            config()->set('pnc.skills', [
                'test.skill' => $content,
            ]);

            $this->artisan('pnc:agent:skills:show', ['skill' => 'test.skill'])
                ->expectsOutputToContain('## Section')
                ->expectsOutputToContain('- Item one')
                ->expectsOutputToContain('- Item two')
                ->expectsOutputToContain('> Blockquote text')
                ->assertSuccessful();
        }

        /**
         * A skill key that exists but maps to an empty string should still
         * succeed (it is a valid entry, just empty).
         */
        #[Test]
        public function handle_succeedsForEmptySkillContent(): void
        {
            config()->set('pnc.skills', [
                'empty.skill' => '',
            ]);

            $this->artisan('pnc:agent:skills:show', ['skill' => 'empty.skill'])
                ->assertSuccessful();
        }
    }   ────────────────────────────────────────────────────────

    /**
     * When the requested skill key does not exist in the registry, the
     * command should error, show a tip, and return FAILURE.
     */
    #[Test]
    public function handle_failsWhenSkillNotFound(): void
    {
        config()->set('pnc.skills', [
            'known.skill' => '# Known Skill',
        ]);

        $this->artisan('pnc:agent:skills:show', ['skill' => 'unknown.skill'])
            ->expectsOutputToContain("Skill 'unknown.skill' not found.")
            ->expectsOutputToContain("Tip: Use 'php artisan agent:skills'")
            ->assertFailed();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Success
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the skill exists, the command should print its markdown
     * content and return SUCCESS.
     */
    #[Test]
    public function handle_printsSkillContentOnSuccess(): void
    {
        $markdown = "# Hook Introduction Writer\n\nYou are a skilled writer who crafts compelling hooks.";

        config()->set('pnc.skills', [
            'developer_docs.core_writing.hook_introduction_writer' => $markdown,
        ]);

        $this->artisan('pnc:agent:skills:show', [
            'skill' => 'developer_docs.core_writing.hook_introduction_writer',
        ])
            ->expectsOutputToContain('Hook Introduction Writer')
            ->expectsOutputToContain('crafts compelling hooks')
            ->assertSuccessful();
    }

    /**
     * The full markdown content (including newlines) should be printed
     * as-is without modification.
     */
    #[Test]
    public function handle_printsFullContentUnmodified(): void
    {
        $content = "# Title\n\n## Section\n\n- Item one\n- Item two\n\n> Blockquote text";

        config()->set('pnc.skills', [
            'test.skill' => $content,
        ]);

        $this->artisan('pnc:agent:skills:show', ['skill' => 'test.skill'])
            ->expectsOutputToContain('## Section')
            ->expectsOutputToContain('- Item one')
            ->expectsOutputToContain('- Item two')
            ->expectsOutputToContain('> Blockquote text')
            ->assertSuccessful();
    }

    /**
     * A skill key that exists but maps to an empty string should still
     * succeed (it is a valid entry, just empty).
     */
    #[Test]
    public function handle_succeedsForEmptySkillContent(): void
    {
        config()->set('pnc.skills', [
            'empty.skill' => '',
        ]);

        $this->artisan('pnc:agent:skills:show', ['skill' => 'empty.skill'])
            ->assertSuccessful();
    }
}
