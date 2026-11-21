<?php

declare(strict_types=1);

namespace Cake\Utility {
    if (!class_exists(Inflector::class)) {
        /** The one Inflector call CakeEntity's setters need, for this test (Booster's vendor has no CakePHP). */
        final class Inflector
        {
            public static function camelize(string $string): string
            {
                return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
            }
        }
    }
}

namespace Waynestate\Booster\Tests {
    // The runtime is a resource Booster writes into apps, not autoloaded here.
    require_once dirname(__DIR__) . '/resources/strangler/cake/CakeEntity.php';

    /**
     * The strangler's CakeEntity runtime, on the rule the CMS tutorial's add
     * needs: as in CakePHP's EntityTrait, a field set (marked dirty) drops
     * the errors validation put on it, so a setter that fills another field
     * (`_setTitle()` writing `slug`) clears slug's requirePresence error.
     */
    final class CakeEntityRuntimeTest extends TestCase
    {
        public function test_a_setter_that_fills_a_field_clears_that_fields_errors(): void
        {
            $article = new CakeEntityRuntimeArticle();
            $article->setErrors(['slug' => ['_required' => 'This field is required'], 'body' => ['_empty' => 'x']]);
            $article->setInvalidField('slug', '');

            $article->cakeSet('title', 'Third Post');

            self::assertSame('Third-Post', $article->attributes['slug']);
            self::assertSame([], $article->getError('slug'), 'slug was set, so its error is gone');
            self::assertNull($article->getInvalidField('slug'));
            self::assertSame(['_empty' => 'x'], $article->getError('body'), 'other fields keep theirs');
            self::assertTrue($article->hasErrors());
        }

        public function test_marking_a_field_clean_keeps_its_errors(): void
        {
            $article = new CakeEntityRuntimeArticle();
            $article->setErrors(['slug' => ['_required' => 'This field is required']]);
            $article->setDirty('slug', false);

            self::assertSame(['_required' => 'This field is required'], $article->getError('slug'));
        }
    }

    /** An entity in the tutorial's shape: the title setter writes the slug. */
    final class CakeEntityRuntimeArticle
    {
        use \App\Http\Controllers\Legacy\Cake\CakeEntity;

        /** @var array<string, mixed> */
        public array $attributes = [];

        public bool $exists = false;

        protected function _setTitle(string $title): string
        {
            $this->cakeSet('slug', str_replace(' ', '-', $title));

            return $title;
        }
    }
}
