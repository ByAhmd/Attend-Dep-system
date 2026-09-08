<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles;

use App\Enums\NavigationGroup;
use App\Filament\Resources\JobTitles\Pages\CreateJobTitle;
use App\Filament\Resources\JobTitles\Pages\EditJobTitle;
use App\Filament\Resources\JobTitles\Pages\ListJobTitles;
use App\Filament\Resources\JobTitles\Schemas\JobTitleForm;
use App\Filament\Resources\JobTitles\Tables\JobTitlesTable;
use App\Models\JobTitle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The job titles an account may be given.
 *
 * Full CRUD, and the only list in this panel the owner extends themselves.
 * It sits under System beside Employees because a title is part of how an
 * account is described, and directly above Attendance settings because both
 * are things configured once and rarely revisited.
 *
 * No $recordTitleAttribute, so this resource never joins global search.
 * That box answers "who" and "which day"; a title is neither, and every
 * searchable resource costs a query on every keystroke.
 */
final class JobTitleResource extends Resource
{
    protected static ?string $model = JobTitle::class;

    protected static ?string $slug = 'job-titles';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Tag;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('job_titles.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('job_titles.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('job_titles.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return JobTitleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobTitlesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobTitles::route('/'),
            'create' => CreateJobTitle::route('/create'),
            'edit' => EditJobTitle::route('/{record}/edit'),
        ];
    }
}
