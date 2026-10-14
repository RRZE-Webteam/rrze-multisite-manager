# Theme overview

## Purpose

The Theme overview lists themes available in the network and their usage on sites.

## Contents

The page combines theme metadata, screenshots, versions, and the number of sites using each theme. Links lead to theme details and the appropriate site details. This makes unused themes easy to identify.

The data comes from dashboard metrics and is updated in the background. Loading the overview does not start a synchronous theme scan across all sites.

## Management

Network-wide theme management remains part of WordPress network administration. Multisite Manager links to the appropriate WordPress functions and makes their network impact visible.

## Implementation

`Dashboard::renderThemeOverviewPage()` uses the theme aggregation produced in the dashboard metrics batch. The metric record retains only bounded previews of sites using each theme; extensive site lists are created only in the detail view.
