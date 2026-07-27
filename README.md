# Pocket Grimoire

[PocketGrimoire.co.uk](https://www.pocketgrimoire.co.uk)

A digital version of the [Blood on the Clocktower](https://bloodontheclocktower.com) grimoire, allowing in-person games.

## Table of Contents

- [Pocket Grimoire](#pocket-grimoire)
  - [Table of Contents](#table-of-contents)
  - [Getting Started](#getting-started)
  - [Run with Docker](#run-with-docker)
    - [Initialise the database](#initialise-the-database)
    - [Common commands](#common-commands)
  - [Custom Scripts](#custom-scripts)
  - [Translations and Typos](#translations-and-typos)

## Getting Started

When you first load the Pocket Grimoire, you'll be presented with a simple screen with the Setup section open and two of the buttons will be disabled.

![The starting screen for the Pocket Grimoire](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/starting-screen.jpg)

Select an edition to load the character data. It's possible to manage custom scripts, see the section lower down for more details.

![The "Select Edition" screen with "Trouble Brewing" selected](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/select-edition.jpg)

Once you've selected your edition, the buttons in the Setup screen will enable. This allows you to tap the "Character sheet" button and reveal a QR code that your players can scan to get see the list of characters on their phones.

![A QR code that will link to the "Trouble Brewing" script](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/qr-code.jpg)

> [!NOTE]
> Notice how the background fully obscures the grimoire instead of blurring it? If the background is blurred then the screen should only be seen by the Story Teller, but if the grimoire is fully hidden by the background then the screen can be shown to the players.

As your players are familiarising themselves with the script, you can close that screen and tap the "Select Characters" button to select the characters . The characters can be selected manually or you can tap the "Highlight random" button to randomly select the tokens for you.

![A series of tokens with numbers on them, from one to seven](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/select-characters.jpg)

> :information_source: If you select the character and a red exclamation mark appears then the character changes the setup of the game. This may mean that other characters need to be selected. A yellow star means that a the character is jinxed with another one - including that character will add a section to the main screen that explains the effect of the Jinx on this game.

When you're happy with the selection, tap the "Draw Characters" button to let your players draw their tokens.

![A series of tokens with numbers on them, from one to seven](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/select-numbers.jpg)

Tapping on any of the numbers will show the token - that will be that player's character. As a token is chosen, that number is greyed out to prevent it being selected again.

When all the tokens have been selected, close that screen and open the Grimoire section. Each of the chosen tokens will be added to the grimoire, with the first token on the bottom and the most recently chosen on at the top.

![The tokens have been added to the grimoire, but they're bunched together](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/tokens-added.jpg)

Organise the grimoire however you prefer, add any reminder tokens that you need, and set up some demon bluffs.

![The tokens have been sorted in the grimoire](https://raw.githubusercontent.com/Skateside/pocket-grimoire/main/assets/img/docs/game-ready.jpg)

You're now ready to play a game of Blood on the Clocktower - have fun!

## Run with Docker

You can work on the project without installing PHP, Composer, Node.js or Yarn locally. All tooling runs inside Docker containers.

1. Copy `.env` to `.env.local` if you need to override defaults. (Optional — the containers already provide an internal MySQL URL.)
2. Build and start the stack:

   ```bash
   docker compose up --build
   ```

   The first startup installs Composer and Yarn dependencies and builds frontend assets before launching the PHP development server at `http://localhost:8000`.

3. Stop the stack with `docker compose down` when you're done.

### Initialise the database

Run these commands from the project root to create the schema and seed the reference data without installing any local tooling:

```bash
# create the tables
docker compose exec app php bin/console doctrine:migrations:migrate

# populate base editions and teams (order matters)
docker compose exec app php bin/console pocket-grimoire:populate-editions --file=assets/data/editions.json --locale=en_GB
docker compose exec app php bin/console pocket-grimoire:populate-teams --file=assets/data/teams.json --locale=en_GB

# create/update the English characters and jinxes
docker compose exec app php bin/console pocket-grimoire:import --new=yes --type=all --locale=en_GB

# import teams, characters, and jinxes in every available non-English locale
docker compose exec app php -d memory_limit=512M bin/console --no-debug pocket-grimoire:import --new=no --type=all --locale=all
```

The `all` locale is supported by `pocket-grimoire:import`, which expands it to every configured locale except the base `en_GB` locale. Editions currently only have base-locale data, so populate them with `--locale=en_GB`; the `pocket-grimoire:populate-editions` command does not support `--locale=all`.

### Common commands

Run project tooling through the `app` service. Examples:

- Install PHP packages (usually handled automatically on container start):

  ```bash
  docker compose run --rm app composer install
  ```

- Execute Symfony console commands:

   ```bash
   docker compose exec app php bin/console doctrine:migrations:migrate
   ```

- Build or watch frontend assets:

  ```bash
  docker compose run --rm app yarn dev        # one-off build
  docker compose run --rm app yarn watch      # rebuild on changes
  ```

All generated files (vendor, `node_modules`, compiled assets) live in Docker volumes, keeping the working tree clean.

## Custom Scripts

As well as the three official scripts, the Pocket Grimoire can allow you to work with custom scripts. A custom script should be a list of IDs for the characters on the script. For example, here's Trouble Brewing as a custom script, including the travellers:

```json
[
    "washerwoman",
    "librarian",
    "investigator",
    "chef",
    "empath",
    "fortuneteller",
    "undertaker",
    "monk",
    "ravenkeeper",
    "virgin",
    "slayer",
    "soldier",
    "mayor",
    "butler",
    "drunk",
    "recluse",
    "saint",
    "poisoner",
    "spy",
    "scarletwoman",
    "baron",
    "imp",
    "bureaucrat",
    "thief",
    "gunslinger",
    "scapegoat",
    "beggar"
]
```

The JSON file created on the [official script tool](https://script.bloodontheclocktower.com/) will be understood.

Optionally, you can include a name for the script. To do this, include an entry in the JSON with the ID `_meta`:

```js
[
    { "id": "_meta", "name": "Trouble Brewing" },
    // ...
]
```

## Translations and Typos

If you've noticed a typo, or you have a better translation or would like to add a new language, there are 3 files that will need to be updated:

1.  [The Community BotC Translations](https://docs.google.com/spreadsheets/d/1aAJdqSTafHnw01w-WZ94UPx1Me70Kz-EG1NFfBht2tA/edit#gid=923580658)
    <br>This file contains translations for the tokens, reminders, and abilities.
    <br>Since this file wasn't created by me, you'll need to let me know of any updates.
    <br>This file has many more translations than the Pocket Grimoire has.

2.  [The Jinxes](https://docs.google.com/spreadsheets/d/193DMlJzVSzArj1hV1DF6jcr-NsGRaecAy1ahLflu-Qo/edit?usp=sharing)
    <br>This file contains translations for the jinxes.
    <br>It was created as a separate file so that it could be easily used in other projects.

3.  [The Pocket Grimoire](https://docs.google.com/spreadsheets/d/1YjI3LcLnLbuONbjbniZTZa1BIT8MKBb8TuIprtmkjAw/edit#gid=19211044)
    <br>This file contains translations for anything that's specific for the Pocket Grimoire.

As soon as a translation exists in all 3 documents, I can add it to the Pocket Grimoire 🙂
