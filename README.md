# Database Writer Common [DBWC]

Common classes for creating vendor specific database writers.

## Installation
Install via composer:

    php composer.phar require db-writer-common

composer.json

    {
      "require": "db-writer-common": ^0.1
    }

## Development

1. Generate SSH key pair for SSH proxy:

        source ./tests/generate-ssh-keys.sh
    
2. Run tests:

        docker-compose run --rm tests
    

    
## License

MIT licensed, see [LICENSE](./LICENSE) file.
