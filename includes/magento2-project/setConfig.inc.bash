codeDir="$projectRoot/app/code"
designDir="$projectRoot/app/dir"
vendorDir="$projectRoot/vendor"

# project var dir, sub directory for qa cache files and output files
varDir="$projectRoot/var/qa";

cacheDir="$projectRoot/cache/qa";

# the path in this library for default config
defaultConfigPath="$(readlink -f $DIR/../configDefaults/)"

# PHPStan configs
phpstanConfigPath="$(configPath phpstan.neon)"

#PHP Mess Detector Configs
phpmdConfigPath="$projectRoot/dev/tests/static/testsuite/Magento/Test/Php/_files/phpmd/ruleset.xml"

# coding Standard for checking
# checks for a folder called 'condingStandards' in the $projectConfigPath, falls back to the PSR2 standards
phpcsCodingStandardsPath="$projectRoot/vendor/magento/marketplace-eqp/MEQP2/"

# should coding standards warnings be a fail?
phpcsFailOnWarning=0

##PHPUnit Configs

# PHPUnit Quick Tests - optional skip slow tests
phpUnitQuickTests=${phpUnitQuickTests:-1}

# PHPUnit Coverage - if enabled, tests will run with Xdebug and generate coverage
phpUnitCoverage=${phpUnitCoverage:-1}

if [[ -f $projectRoot/dev/tests/unit/phpunit.xml ]]
then
    # Project root phpunit.xml trumps everything else
    phpUnitConfigPath=$projectRoot/dev/tests/unit/phpunit.xml
elif [[ -f $projectRoot/dev/tests/unit/phpunit.xml.dist ]]
then
    # Project root phpunit.xml trumps everything else
    phpUnitConfigPath=$projectRoot/dev/tests/unit/phpunit.xml.dist
else
    echo "No PHPUnit config was found at either:"
    echo "- $projectRoot/dev/tests/unit/phpunit.xml"
    echo "- $projectRoot/dev/tests/unit/phpunit.xml.dist"
fi

pathsToCheck=();

if [[ -d "$projectRoot/app/code" ]]
then
    pathsToCheck+="$projectRoot/app/code"
fi
if [[ -d "$projectRoot/app/design" ]]
then
    pathsToCheck+="$projectRoot/app/design"
fi
if [[ -d "$projectRoot/vendor" ]]
then
    pathsToCheck+="$projectRoot/vendor"
fi




pathsToIgnore=();

# To renew this list, navigate to a Magento2 that only has phpqa installed, cd to the vendor folder and run:
# find . -maxdepth 2 -type d | grep -P "/.*/" | sed "s#./#\pathsToIgnore+=\"vendor/#" | sed "s#\$#\"#"
pathsToIgnore+="/vendor/magento/language-zh_hans_cn"
pathsToIgnore+="/vendor/magento/language-pt_br"
pathsToIgnore+="/vendor/magento/language-nl_nl"
pathsToIgnore+="/vendor/magento/language-fr_fr"
pathsToIgnore+="/vendor/magento/language-es_es"
pathsToIgnore+="/vendor/magento/language-en_us"
pathsToIgnore+="/vendor/magento/language-de_de"
pathsToIgnore+="/vendor/magento/module-require-js"
pathsToIgnore+="/vendor/magento/module-media-storage"
pathsToIgnore+="/vendor/magento/module-developer"
pathsToIgnore+="/vendor/magento/module-cms-url-rewrite"
pathsToIgnore+="/vendor/magento/module-authorization"
pathsToIgnore+="/vendor/magento/module-security"
pathsToIgnore+="/vendor/magento/module-rule"
pathsToIgnore+="/vendor/magento/module-msrp"
pathsToIgnore+="/vendor/magento/module-gift-message"
pathsToIgnore+="/vendor/magento/module-sales-sequence"
pathsToIgnore+="/vendor/magento/module-product-alert"
pathsToIgnore+="/vendor/magento/module-rss"
pathsToIgnore+="/vendor/magento/module-weee"
pathsToIgnore+="/vendor/magento/module-webapi-security"
pathsToIgnore+="/vendor/magento/module-version"
pathsToIgnore+="/vendor/magento/module-usps"
pathsToIgnore+="/vendor/magento/module-tax-import-export"
pathsToIgnore+="/vendor/magento/module-swatches-layered-navigation"
pathsToIgnore+="/vendor/magento/module-swagger"
pathsToIgnore+="/vendor/magento/module-robots"
pathsToIgnore+="/vendor/magento/module-signifyd"
pathsToIgnore+="/vendor/magento/module-send-friend"
pathsToIgnore+="/vendor/magento/module-sales-inventory"
pathsToIgnore+="/vendor/magento/module-persistent"
pathsToIgnore+="/vendor/magento/module-offline-payments"
pathsToIgnore+="/vendor/magento/module-multishipping"
pathsToIgnore+="/vendor/magento/module-layered-navigation"
pathsToIgnore+="/vendor/magento/module-grouped-import-export"
pathsToIgnore+="/vendor/magento/module-cookie"
pathsToIgnore+="/vendor/magento/module-google-analytics"
pathsToIgnore+="/vendor/magento/module-google-adwords"
pathsToIgnore+="/vendor/magento/module-fedex"
pathsToIgnore+="/vendor/magento/module-encryption-key"
pathsToIgnore+="/vendor/magento/module-downloadable-import-export"
pathsToIgnore+="/vendor/magento/module-dhl"
pathsToIgnore+="/vendor/magento/module-currency-symbol"
pathsToIgnore+="/vendor/magento/module-configurable-product-sales"
pathsToIgnore+="/vendor/magento/module-configurable-import-export"
pathsToIgnore+="/vendor/magento/module-checkout-agreements"
pathsToIgnore+="/vendor/magento/module-catalog-widget"
pathsToIgnore+="/vendor/magento/module-catalog-rule-configurable"
pathsToIgnore+="/vendor/magento/module-captcha"
pathsToIgnore+="/vendor/magento/module-cache-invalidate"
pathsToIgnore+="/vendor/magento/module-bundle-import-export"
pathsToIgnore+="/vendor/magento/module-authorizenet"
pathsToIgnore+="/vendor/magento/module-advanced-pricing-import-export"
pathsToIgnore+="/vendor/magento/module-marketplace"
pathsToIgnore+="/vendor/magento/composer"
pathsToIgnore+="/vendor/magento/zendframework1"
pathsToIgnore+="/vendor/magento/data-migration-tool"
pathsToIgnore+="/vendor/magento/magento-composer-installer"
pathsToIgnore+="/vendor/magento/framework"
pathsToIgnore+="/vendor/magento/module-config"
pathsToIgnore+="/vendor/magento/module-deploy"
pathsToIgnore+="/vendor/magento/module-store"
pathsToIgnore+="/vendor/magento/module-ui"
pathsToIgnore+="/vendor/magento/module-user"
pathsToIgnore+="/vendor/magento/module-email"
pathsToIgnore+="/vendor/magento/module-variable"
pathsToIgnore+="/vendor/magento/module-backend"
pathsToIgnore+="/vendor/magento/module-translation"
pathsToIgnore+="/vendor/magento/module-quote"
pathsToIgnore+="/vendor/magento/module-catalog-inventory"
pathsToIgnore+="/vendor/magento/module-page-cache"
pathsToIgnore+="/vendor/magento/module-widget"
pathsToIgnore+="/vendor/magento/module-theme"
pathsToIgnore+="/vendor/magento/module-eav"
pathsToIgnore+="/vendor/magento/module-catalog"
pathsToIgnore+="/vendor/magento/module-url-rewrite"
pathsToIgnore+="/vendor/magento/module-catalog-url-rewrite"
pathsToIgnore+="/vendor/magento/module-cms"
pathsToIgnore+="/vendor/magento/module-sales"
pathsToIgnore+="/vendor/magento/module-customer"
pathsToIgnore+="/vendor/magento/module-integration"
pathsToIgnore+="/vendor/magento/module-wishlist"
pathsToIgnore+="/vendor/magento/module-shipping"
pathsToIgnore+="/vendor/magento/module-tax"
pathsToIgnore+="/vendor/magento/module-reports"
pathsToIgnore+="/vendor/magento/module-sales-rule"
pathsToIgnore+="/vendor/magento/module-catalog-rule"
pathsToIgnore+="/vendor/magento/module-directory"
pathsToIgnore+="/vendor/magento/module-grouped-product"
pathsToIgnore+="/vendor/magento/module-checkout"
pathsToIgnore+="/vendor/magento/module-payment"
pathsToIgnore+="/vendor/magento/module-downloadable"
pathsToIgnore+="/vendor/magento/module-contact"
pathsToIgnore+="/vendor/magento/module-newsletter"
pathsToIgnore+="/vendor/magento/module-review"
pathsToIgnore+="/vendor/magento/module-indexer"
pathsToIgnore+="/vendor/magento/module-import-export"
pathsToIgnore+="/vendor/magento/module-catalog-import-export"
pathsToIgnore+="/vendor/magento/module-cron"
pathsToIgnore+="/vendor/magento/module-backup"
pathsToIgnore+="/vendor/magento/theme-frontend-blank"
pathsToIgnore+="/vendor/magento/theme-frontend-luma"
pathsToIgnore+="/vendor/magento/theme-adminhtml-backend"
pathsToIgnore+="/vendor/magento/module-wishlist-analytics"
pathsToIgnore+="/vendor/magento/module-webapi"
pathsToIgnore+="/vendor/magento/module-vault"
pathsToIgnore+="/vendor/magento/module-ups"
pathsToIgnore+="/vendor/magento/module-configurable-product"
pathsToIgnore+="/vendor/magento/module-swatches"
pathsToIgnore+="/vendor/magento/module-sitemap"
pathsToIgnore+="/vendor/magento/module-search"
pathsToIgnore+="/vendor/magento/module-catalog-search"
pathsToIgnore+="/vendor/magento/module-sample-data"
pathsToIgnore+="/vendor/magento/module-sales-analytics"
pathsToIgnore+="/vendor/magento/module-review-analytics"
pathsToIgnore+="/vendor/magento/module-release-notification"
pathsToIgnore+="/vendor/magento/module-quote-analytics"
pathsToIgnore+="/vendor/magento/module-product-video"
pathsToIgnore+="/vendor/magento/module-instant-purchase"
pathsToIgnore+="/vendor/magento/module-paypal"
pathsToIgnore+="/vendor/magento/module-offline-shipping"
pathsToIgnore+="/vendor/magento/module-new-relic-reporting"
pathsToIgnore+="/vendor/magento/module-google-optimizer"
pathsToIgnore+="/vendor/magento/module-customer-import-export"
pathsToIgnore+="/vendor/magento/module-customer-analytics"
pathsToIgnore+="/vendor/magento/module-catalog-analytics"
pathsToIgnore+="/vendor/magento/module-bundle"
pathsToIgnore+="/vendor/magento/module-braintree"
pathsToIgnore+="/vendor/magento/module-analytics"
pathsToIgnore+="/vendor/magento/module-admin-notification"
pathsToIgnore+="/vendor/magento/magento2-base"
pathsToIgnore+="/vendor/magento/marketplace-eqp"
pathsToIgnore+="/vendor/composer/semver"
pathsToIgnore+="/vendor/composer/composer"
pathsToIgnore+="/vendor/composer/ca-bundle"
pathsToIgnore+="/vendor/composer/spdx-licenses"
pathsToIgnore+="/vendor/shopialfb/facebook-module"
pathsToIgnore+="/vendor/zendframework/zend-stdlib"
pathsToIgnore+="/vendor/zendframework/zend-hydrator"
pathsToIgnore+="/vendor/zendframework/zend-escaper"
pathsToIgnore+="/vendor/zendframework/zend-uri"
pathsToIgnore+="/vendor/zendframework/zend-loader"
pathsToIgnore+="/vendor/zendframework/zend-http"
pathsToIgnore+="/vendor/zendframework/zend-filter"
pathsToIgnore+="/vendor/zendframework/zend-math"
pathsToIgnore+="/vendor/zendframework/zend-crypt"
pathsToIgnore+="/vendor/zendframework/zend-code"
pathsToIgnore+="/vendor/zendframework/zend-captcha"
pathsToIgnore+="/vendor/zendframework/zend-log"
pathsToIgnore+="/vendor/zendframework/zend-json"
pathsToIgnore+="/vendor/zendframework/zend-di"
pathsToIgnore+="/vendor/zendframework/zend-config"
pathsToIgnore+="/vendor/zendframework/zend-i18n"
pathsToIgnore+="/vendor/zendframework/zend-text"
pathsToIgnore+="/vendor/zendframework/zend-server"
pathsToIgnore+="/vendor/zendframework/zend-console"
pathsToIgnore+="/vendor/zendframework/zend-db"
pathsToIgnore+="/vendor/zendframework/zend-validator"
pathsToIgnore+="/vendor/zendframework/zend-servicemanager"
pathsToIgnore+="/vendor/zendframework/zend-inputfilter"
pathsToIgnore+="/vendor/zendframework/zend-form"
pathsToIgnore+="/vendor/zendframework/zend-eventmanager"
pathsToIgnore+="/vendor/zendframework/zend-modulemanager"
pathsToIgnore+="/vendor/zendframework/zend-serializer"
pathsToIgnore+="/vendor/zendframework/zend-soap"
pathsToIgnore+="/vendor/zendframework/zend-view"
pathsToIgnore+="/vendor/zendframework/zend-psr7bridge"
pathsToIgnore+="/vendor/zendframework/zend-mvc"
pathsToIgnore+="/vendor/zendframework/zend-session"
pathsToIgnore+="/vendor/zendframework/zend-diactoros"
pathsToIgnore+="/vendor/psr/container"
pathsToIgnore+="/vendor/psr/log"
pathsToIgnore+="/vendor/psr/http-message"
pathsToIgnore+="/vendor/container-interop/container-interop"
pathsToIgnore+="/vendor/tedivm/jshrink"
pathsToIgnore+="/vendor/symfony/debug"
pathsToIgnore+="/vendor/symfony/polyfill-mbstring"
pathsToIgnore+="/vendor/symfony/polyfill-php54"
pathsToIgnore+="/vendor/symfony/polyfill-php55"
pathsToIgnore+="/vendor/symfony/polyfill-php70"
pathsToIgnore+="/vendor/symfony/polyfill-php72"
pathsToIgnore+="/vendor/symfony/console"
pathsToIgnore+="/vendor/symfony/event-dispatcher"
pathsToIgnore+="/vendor/symfony/process"
pathsToIgnore+="/vendor/symfony/filesystem"
pathsToIgnore+="/vendor/symfony/config"
pathsToIgnore+="/vendor/symfony/dependency-injection"
pathsToIgnore+="/vendor/symfony/finder"
pathsToIgnore+="/vendor/symfony/stopwatch"
pathsToIgnore+="/vendor/oyejorge/less.php"
pathsToIgnore+="/vendor/monolog/monolog"
pathsToIgnore+="/vendor/seld/phar-utils"
pathsToIgnore+="/vendor/seld/cli-prompt"
pathsToIgnore+="/vendor/seld/jsonlint"
pathsToIgnore+="/vendor/justinrainbow/json-schema"
pathsToIgnore+="/vendor/colinmollenhour/credis"
pathsToIgnore+="/vendor/colinmollenhour/php-redis-session-abstract"
pathsToIgnore+="/vendor/colinmollenhour/cache-backend-file"
pathsToIgnore+="/vendor/colinmollenhour/cache-backend-redis"
pathsToIgnore+="/vendor/paragonie/random_compat"
pathsToIgnore+="/vendor/braintree/braintree_php"
pathsToIgnore+="/vendor/ramsey/uuid"
pathsToIgnore+="/vendor/league/climate"
pathsToIgnore+="/vendor/sjparkinson/static-review"
pathsToIgnore+="/vendor/phpseclib/phpseclib"
pathsToIgnore+="/vendor/tubalmartin/cssmin"
pathsToIgnore+="/vendor/pelago/emogrifier"
pathsToIgnore+="/vendor/squizlabs/php_codesniffer"
pathsToIgnore+="/vendor/lusitanian/oauth"
pathsToIgnore+="/vendor/theseer/fdomdocument"
pathsToIgnore+="/vendor/theseer/tokenizer"
pathsToIgnore+="/vendor/sebastian/version"
pathsToIgnore+="/vendor/sebastian/resource-operations"
pathsToIgnore+="/vendor/sebastian/recursion-context"
pathsToIgnore+="/vendor/sebastian/object-reflector"
pathsToIgnore+="/vendor/sebastian/object-enumerator"
pathsToIgnore+="/vendor/sebastian/global-state"
pathsToIgnore+="/vendor/sebastian/exporter"
pathsToIgnore+="/vendor/sebastian/environment"
pathsToIgnore+="/vendor/sebastian/diff"
pathsToIgnore+="/vendor/sebastian/comparator"
pathsToIgnore+="/vendor/sebastian/code-unit-reverse-lookup"
pathsToIgnore+="/vendor/sebastian/phpcpd"
pathsToIgnore+="/vendor/sebastian/finder-facade"
pathsToIgnore+="/vendor/doctrine/instantiator"
pathsToIgnore+="/vendor/phpunit/php-text-template"
pathsToIgnore+="/vendor/phpunit/phpunit-mock-objects"
pathsToIgnore+="/vendor/phpunit/php-timer"
pathsToIgnore+="/vendor/phpunit/phpunit"
pathsToIgnore+="/vendor/phpunit/php-token-stream"
pathsToIgnore+="/vendor/phpunit/php-file-iterator"
pathsToIgnore+="/vendor/phpunit/php-code-coverage"
pathsToIgnore+="/vendor/webmozart/assert"
pathsToIgnore+="/vendor/phpdocumentor/reflection-common"
pathsToIgnore+="/vendor/phpdocumentor/type-resolver"
pathsToIgnore+="/vendor/phpdocumentor/reflection-docblock"
pathsToIgnore+="/vendor/phpspec/prophecy"
pathsToIgnore+="/vendor/phar-io/version"
pathsToIgnore+="/vendor/phar-io/manifest"
pathsToIgnore+="/vendor/myclabs/deep-copy"
pathsToIgnore+="/vendor/pdepend/pdepend"
pathsToIgnore+="/vendor/phpmd/phpmd"
pathsToIgnore+="/vendor/ircmaxell/password-compat"
pathsToIgnore+="/vendor/friendsofphp/php-cs-fixer"
pathsToIgnore+="/vendor/edmondscommerce/magento2-data-migration"
pathsToIgnore+="/vendor/edmondscommerce/magento2styleguide"
pathsToIgnore+="/vendor/edmondscommerce/phpqa"
pathsToIgnore+="/vendor/temando/module-shipping-m2"
pathsToIgnore+="/vendor/dotmailer/dotmailer-magento2-extension"
pathsToIgnore+="/vendor/phpstan/phpstan-shim"
pathsToIgnore+="/vendor/jakub-onderka/php-parallel-lint"
pathsToIgnore+="/vendor/jakub-onderka/php-console-color"
pathsToIgnore+="/vendor/jakub-onderka/php-console-highlighter"
pathsToIgnore+="/vendor/phploc/phploc"
pathsToIgnore+="/vendor/johnkary/phpunit-speedtrap"