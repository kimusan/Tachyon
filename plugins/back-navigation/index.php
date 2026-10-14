<?php
/**
 * back-navigation — the browser's Back button closes the opened message and returns to the list, like Gmail.
 * Opening a message pushes "#/mailbox/<folder>/m<uid>" into the history (a route the core already reads but never
 * writes); Back, or the close button, pops it. Client-side only: one script, no server action.
 * License: AGPL-3.0 (see LICENSE).
 */

class BackNavigationPlugin extends \Tachyon\Plugins\AbstractPlugin
{
    const NAME = 'Back Navigation';
    const VERSION = '0.1'; // bump on every change of back-navigation.js (the bundle is cached by name@version)
    const RELEASE = '2026-10-14';
    const REQUIRED = '2.36.0';
    const CATEGORY = 'Messages';
    const DESCRIPTION = 'The browser Back button closes the opened message: opening a message pushes #/mailbox/<folder>/m<uid> into the history, Back or the close button pops it.';

    public function Init(): void
    {
        $this->addJs('back-navigation.js');
    }
}
