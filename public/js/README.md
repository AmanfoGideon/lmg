# Browser Configuration

Enter the Supabase project URL and publishable/anon key in `config.js`. These values are public and may be committed; Row Level Security is the access boundary. Never place a service-role key or provider credential in this file.

The existing PHP entry points remain the active application until their replacements have been migrated and verified. `config.example.js` documents the expected shape; local browser config is only for the new static Supabase client.