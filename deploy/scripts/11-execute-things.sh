#!/usr/bin/env sh

echo "Now in 'web' variant of execute-things.sh"

# execute things yay
echo "Now running finalize-image.sh"
$(which bash) /usr/local/bin/finalize-image.sh

echo "Now running entrypoint.sh"
$(which bash) /usr/local/bin/entrypoint.sh

if [ "$WEB_SERVER" = "false" ]
then
	echo "--------------------------------------------------------"
	echo "WEB_SERVER=false no longer works on this image."
	echo "Please use fireflyiii/data-importer:latest-cli instead"
	echo "--------------------------------------------------------"
    exit 1
fi
