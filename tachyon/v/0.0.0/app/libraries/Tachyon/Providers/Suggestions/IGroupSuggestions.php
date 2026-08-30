<?php

namespace Tachyon\Providers\Suggestions;

/**
 * Optional interface for suggestion drivers that can expand a named contact
 * group (vCard CATEGORIES) into individual email addresses.
 *
 * Implement this alongside ISuggestions to enable group expansion in the
 * compose autocomplete: typing an exact category name returns all members.
 */
interface IGroupSuggestions
{
	/**
	 * Return [email, displayName] pairs for contacts in the named category.
	 * Return [] if the category is unknown or the driver can't support it.
	 *
	 * @return array  Array of [string $email, string $name]
	 */
	public function GetGroup(string $sCategoryName, int $iLimit = 20) : array;

	/**
	 * Return [name, memberCount] pairs for categories matching the query, so the
	 * autocomplete can offer the group before anyone knows its exact name.
	 *
	 * Optional: callers check with method_exists, so a driver written before
	 * this existed keeps working and simply offers no groups of its own.
	 *
	 * @return array  Array of [string $name, int|string $count]
	 */
//	public function GetMatchingCategories(string $sQuery, int $iLimit = 5) : array;
}
