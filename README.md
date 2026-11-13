# 📈 TradeScope

**AI-powered market analysis dashboard with live TradingView charts and projected price paths.**

TradeScope continuously produces technical analyses of crypto (and, secondarily, stocks and forex) using a Large Language Model, and overlays the projected future price path directly on a live chart. It runs on standard shared hosting, with no persistent processes required.

---

## ✨ Features

- 🤖 **AI analysis engine** — periodic analyses generated through the **Anthropic Claude API**, with **OpenAI** available as an alternative provider
- 🧭 **Confluence engine** — combines multiple signals, including **Smart Money Concepts (SMC)**, into a single scored view
- 📊 **Live charts** — embedded **TradingView** charts with the projected price path drawn on top
- 🪙 **Crypto-first** — focus on crypto markets, with support for stocks and forex
- 🔒 **Private access** — protected login, single-owner dashboard
- ☁️ **Shared-hosting friendly** — PHP + MySQL with scheduled jobs, deployable on plans without Node or long-running processes

## 🏗️ Architecture

```
 Scheduled job ──▶ Market data ──▶ Confluence / SMC engine ──▶ LLM (Claude / OpenAI)
                                                                     │
                                         MySQL (analyses, history) ◀─┘
                                                   │
                              Pro dashboard (login) + TradingView chart overlay
```

## 🛠️ Tech stack

![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=flat-square&logo=javascript&logoColor=black)
![TradingView](https://img.shields.io/badge/TradingView-131722?style=flat-square&logo=tradingview&logoColor=white)
![Claude API](https://img.shields.io/badge/Claude%20API-D97757?style=flat-square&logo=anthropic&logoColor=white)
![OpenAI](https://img.shields.io/badge/OpenAI-412991?style=flat-square&logo=openai&logoColor=white)

## 🗺️ Status

Current version: **2.x** — confluence/SMC engine and redesigned "pro" interface.

## ⚠️ Disclaimer

TradeScope is a personal analysis tool and does not constitute financial advice.

## 📄 License

© Federico Murgese — All rights reserved.
