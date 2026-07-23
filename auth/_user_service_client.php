<?php

// Single swap point for the real user-services integration. login.php never talks to
// user-services directly — it only calls this function. Returns the member's identifier
// (matched against membership.member_identifier) on success, or null on failure.
//
// ponytail: no user-services exists yet — this always succeeds for any non-empty
// member_identifier/password, treating $member_identifier itself as the resolved identity.
// Absolute tech debt, accepted knowingly: swap this function body for a real HTTP call to
// user-services (send $member_identifier/$password, expect back the canonical member identity
// on 200, null on 401/anything else) when that service exists. No other file needs to change.
function resolve_user_identity($member_identifier, $password) {
    if ($member_identifier === '' || $password === '') {
        return null;
    }
    return $member_identifier;
}

?>
