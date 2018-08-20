<?php

namespace Spatie\Sluggable;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

trait HasSlug
{
    /** @var \Spatie\Sluggable\SlugOptions */
    protected $slugOptions;

    /**
     * Get the options for generating the slug.
     */
    abstract public function getSlugOptions(): SlugOptions;

    /**
     * Boot the trait.
     */
    protected static function bootHasSlug()
    {
        static::creating(function (Model $model) {
            $model->generateSlugOnCreate();
        });

        static::updating(function (Model $model) {
            $model->generateSlugOnUpdate();
        });
    }

    /**
     * Handle adding slug on model creation.
     */
    protected function generateSlugOnCreate()
    {
        $this->slugOptions = $this->getSlugOptions();

        if (!$this->slugOptions->generateSlugsOnCreate) {
            return;
        }

        $this->addSlug();
    }

    /**
     * Handle adding slug on model update.
     */
    protected function generateSlugOnUpdate()
    {
        $this->slugOptions = $this->getSlugOptions();

        if (!$this->slugOptions->generateSlugsOnUpdate) {
            return;
        }

        $this->addSlug();
    }

    /**
     * Handle setting slug on explicit request.
     */
    public function generateSlug()
    {
        $this->slugOptions = $this->getSlugOptions();

        $this->addSlug();
    }

    /**
     * Add the slug to the model.
     */
    protected function addSlug()
    {
        $this->guardAgainstInvalidSlugOptions();

        $slug = $this->generateNonUniqueSlug();

        if ($this->slugOptions->generateUniqueSlugs) {
            $slug = $this->makeSlugUnique($slug);
        }

        $slugField = $this->slugOptions->slugField;

        $this->$slugField = $slug;
    }

    /**
     * Generate a non unique slug for this record.
     */
    protected function generateNonUniqueSlug()
    {
        if ($this->hasCustomSlugBeenUsed()) {
            $slugField = $this->slugOptions->slugField;

            return $this->$slugField;
        }


        $slug = $this->getSlugSource();
        if (is_array($slug)) {
            foreach ($slug as $lang => &$slugItem) {
                $slugItem = Str::slug($slugItem, $this->slugOptions->slugSeparator, $lang);
            }
        } else {
            $slug = Str::slug($slug, $this->slugOptions->slugSeparator, $this->slugOptions->slugLanguage);
        }
        return $slug;
    }

    /**
     * Determine if a custom slug has been saved.
     */
    protected function hasCustomSlugBeenUsed(): bool
    {
        $slugField = $this->slugOptions->slugField;
        if ($this->isSlugTranslatable()) {
            return json_decode($this->getOriginal($slugField), true) != $this->getTranslations($slugField);
        }
        return $this->getOriginal($slugField) != $this->$slugField;
    }

    /**
     * Get the string that should be used as base for the slug.
     */
    protected function getSlugSource()
    {
        if ($this->isSlugTranslatable()) {
            $slugSource = $this->getTranslations($this->slugOptions->generateSlugFrom[0]);
            foreach ($slugSource as &$item) {
                $item = substr($item, 0, $this->slugOptions->maximumLength);
            }
            return $slugSource;
        } elseif (is_callable($this->slugOptions->generateSlugFrom)) {
            $slugSourceString = call_user_func($this->slugOptions->generateSlugFrom, $this);

            return substr($slugSourceString, 0, $this->slugOptions->maximumLength);
        }

        $slugSourceString = collect($this->slugOptions->generateSlugFrom)
            ->map(function (string $fieldName): string {
                return $this->$fieldName ?? '';
            })
            ->implode($this->slugOptions->slugSeparator);

        return substr($slugSourceString, 0, $this->slugOptions->maximumLength);
    }

    /**
     * Make the given slug unique.
     */
    protected function makeSlugUnique($slug)
    {
        if (is_array($slug)) {
            foreach ($slug as $lang => &$slugItem) {
                $slugItem = $this->makeSlugStringUnique($slugItem, $lang);
            }
        } else {
            $slug = $this->makeSlugStringUnique($slug);
        }

        return $slug;
    }

    /**
     * Make the given slug string unique.
     */
    protected function makeSlugStringUnique(string $slug, $lang = null): string
    {
        $originalSlug = $slug;
        $i = 1;

        while ($this->otherRecordExistsWithSlug($slug, $lang) || $slug === '') {
            $slug = $originalSlug . $this->slugOptions->slugSeparator . $i++;
        }

        return $slug;
    }

    /**
     * Determine if a record exists with the given slug.
     */
    protected function otherRecordExistsWithSlug(string $slug, string $lang = null): bool
    {
        $key = $this->getKey();

        if ($this->incrementing) {
            $key = $key ?? '0';
        }

        return (bool)static::where($this->slugOptions->slugField . ($lang ? "->$lang" : ''), $slug)
            ->where($this->getKeyName(), '!=', $key)
            ->withoutGlobalScopes()
            ->first();
    }

    /**
     * This function will throw an exception when any of the options is missing or invalid.
     */
    protected function guardAgainstInvalidSlugOptions()
    {
        if (is_array($this->slugOptions->generateSlugFrom) && !count($this->slugOptions->generateSlugFrom)) {
            throw InvalidOption::missingFromField();
        }

        if (!strlen($this->slugOptions->slugField)) {
            throw InvalidOption::missingSlugField();
        }

        if ($this->slugOptions->maximumLength <= 0) {
            throw InvalidOption::invalidMaximumLength();
        }
    }

    /**
     * Determine if a slug translatable
     */
    private function isSlugTranslatable()
    {
        return !empty($this->translatable)
            && in_array($this->slugOptions->generateSlugFrom[0], $this->translatable)
            && method_exists($this, 'getTranslations');
    }
}
