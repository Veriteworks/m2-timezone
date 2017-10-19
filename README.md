# Magento2-Timezone

Module Magento Timezone issue. <br />
on catalog rule indexing time zone always set as UTC.
which creates problem with different timezone stores and their cron jobs.

This module assign the timezone depending on store local time.
Store local time is determine from admin, or store config "general/locale/timezone".

After Indexing and updating from and to time, this module revert the timezone to Magento's default one.
