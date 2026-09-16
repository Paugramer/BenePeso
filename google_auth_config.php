<?php

/**
 * Google Identity Services public web client identifier.
 *
 * GOOGLE_CLIENT_ID can override this value on production hosting without
 * changing application files. A client ID is public; never place a Google
 * client secret in browser markup or commit it to this project.
 */
function benepeso_google_client_id(): string
{
    return trim((string)(getenv('GOOGLE_CLIENT_ID') ?: '762188274091-gh7ao5e0cicqf6nse9k37t8ce7apsbf4.apps.googleusercontent.com'));
}

