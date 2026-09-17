# SectionEdit for Datenstrom Yellow 0.9

SectionEdit adds editing of individual Markdown sections while keeping Yellow's normal Edit extension responsible for authentication, CSRF handling, access checks, page validation and 3-way merging.

## Version

0.1.23

## Requirements

- Datenstrom Yellow 0.9
- Yellow Edit extension enabled

## Installation

Download the repository ZIP from the `main` branch and install it as a Yellow extension. The extension metadata maps the worker and its implementation parts into `system/workers/`.

## Section IDs

Sections use structural IDs such as `1`, `1.1`, `1.1.2`. ATX and Setext headings are supported; front matter and fenced code blocks are ignored by the scanner.

## Current UI

The section editor reuses Yellow's Edit stylesheet and site theme, keeps the framed editing area, and provides Save/Cancel links with section-anchor return behavior.
