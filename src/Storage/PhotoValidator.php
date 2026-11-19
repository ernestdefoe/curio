<?php

namespace Ernestdefoe\Curio\Storage;

use Ernestdefoe\Curio\Settings;
use Flarum\Foundation\AbstractImageValidator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Validation\Factory;
use Intervention\Image\ImageManager;

/** A member's photo: JPEG, PNG or WebP, within the admin's size limit. */
class PhotoValidator extends AbstractImageValidator
{
    public function __construct(Factory $validator, TranslatorInterface $translator, ImageManager $imageManager, private SettingsRepositoryInterface $settings)
    {
        parent::__construct($validator, $translator, $imageManager);
    }

    public function getMaxSize(): int
    {
        return (int) $this->settings->get(Settings::MAX_UPLOAD_KB);
    }

    /** @return list<string> */
    protected function getAllowedTypes(): array
    {
        return ['jpeg', 'jpg', 'png', 'webp'];
    }
}
