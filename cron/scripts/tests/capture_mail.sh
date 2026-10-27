#!/bin/sh
printf "X-Sendmail-Args: %s\n" "$*" >> "$MAIL_LOG"
cat >> "$MAIL_LOG"
printf '\n=====END-OF-MAIL=====\n' >> "$MAIL_LOG"
