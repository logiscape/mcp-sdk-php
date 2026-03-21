# MCP SDK for PHP - Test Suite

This directory contains the PHPUnit-based test suite for the Model Context Protocol (MCP) SDK for PHP. These tests ensure the reliability and correctness of the SDK's core functionality.

## Purpose of Unit Tests

Unit tests validate that individual components of the SDK work correctly in isolation. They serve several critical purposes:

- **Regression Prevention**: Catch bugs before they reach production
- **Documentation**: Tests demonstrate how the SDK components should be used
- **Refactoring Safety**: Allow confident code improvements by detecting breaking changes
- **Specification Compliance**: Ensure the SDK implements the MCP protocol correctly

## Quick Start

### Prerequisites

- PHP 8.1 or higher
- Composer (for dependency management)

### Installation

After cloning the repository, install dependencies including PHPUnit:

```bash
composer install
```

### Running Tests

Run all tests:
```bash
vendor/bin/phpunit
```

Run a specific test file:
```bash
vendor/bin/phpunit tests/Client/ClientSessionInitializeTest.php
```

Run tests with coverage report (requires Xdebug):
```bash
vendor/bin/phpunit --coverage-html coverage/
```

### Test Structure

Tests mirror the source tree structure. For example:
- Source: `src/Client/ClientSession.php`
- Test: `tests/Client/ClientSessionTest.php`

All test classes must be suffixed with `Test` to be automatically discovered by PHPUnit.

## Core Test Suite

The SDK includes four critical test files that validate the protocol's core functionality:

### 1. ClientSessionInitializeTest.php

**Location**: `tests/Client/ClientSessionInitializeTest.php`
**Source Code**: `src/Client/ClientSession.php:130-170`

**Purpose**: Validates the client-side initialization handshake sequence.

This test is critical because if the handshake is broken, clients cannot connect to ANY MCP server. It validates:

- **Correct request sequence**: initialize request → wait for response → initialized notification
- **Protocol version validation**: Ensures clients reject unsupported protocol versions
- **State transitions**: Verifies proper initialization state management
- **Feature detection**: Confirms feature support is correctly reported based on negotiated protocol version

**Key Tests**:
- `testInitializeHandshakeSendsCorrectSequence()` - Verifies exact message sequence
- `testGetInitializeResultSucceedsAfterInitialization()` - Validates result storage
- `testGetNegotiatedProtocolVersionSucceedsAfterInitialization()` - Confirms version negotiation
- `testInitializeRejectsUnsupportedProtocolVersion()` - Validates version rejection
- `testSupportsFeatureReturnsFalseBeforeInitialization()` - Tests pre-initialization state
- `testInitializeNegotiatesOlderSupportedProtocolVersion()` - Tests backward compatibility with older protocol versions
- `testInitializeFailsOnJsonRpcErrorResponse()` - Tests error handling when server returns JSONRPCError during init
- `testInitializeTimesOutWhenServerNeverResponds()` - Tests timeout detection when server is unresponsive

**Why Critical**: The initialization handshake is the first interaction between client and server. If this fails, no communication can occur.

---

### 2. ServerSessionTest.php

**Location**: `tests/Server/ServerSessionTest.php`
**Source Code**: `src/Server/ServerSession.php:231-269`

**Purpose**: Validates server-side protocol version negotiation.

This test ensures servers can negotiate with clients using different protocol versions, enabling backward compatibility. It validates:

- **Version downgrade logic**: Server accepts older client versions
- **Version negotiation storage**: `negotiatedProtocolVersion` is correctly set
- **State transitions**: Proper movement to `Initialized` state
- **InitializeResult construction**: Server info and capabilities are correctly populated

**Key Tests**:
- `testInitializeNegotiatesCommonProtocolVersion()` - Validates version downgrade (client requests older supported version)
- `testInitializeAcceptsLatestProtocolVersion()` - Tests happy path (both on latest version)
- `testInitializeFallsBackToLatestForUnsupportedVersion()` - Validates fallback for unsupported versions
- `testInitializeReturnsCorrectServerInfo()` - Verifies server metadata in response
- `testIncomingInitializeMessageFlowsThroughBaseSession()` - Tests full JSON-RPC routing path through BaseSession
- `testResponsesAdaptedForOlderProtocolVersion()` - Tests protocol adaptation for backward compatibility

**Why Critical**: Without proper version negotiation, servers cannot support clients running different SDK versions, breaking backward compatibility.

**Example Edge Case**: Client requests `2024-11-05` but server prefers `2025-03-26` → server should negotiate to `2024-11-05` (common ground).

---

### 3. BaseSessionTest.php

**Location**: `tests/Shared/BaseSessionTest.php`
**Source Code**: `src/Shared/BaseSession.php:109-320`

**Purpose**: Validates JSON-RPC error propagation, response handler cleanup, and server-side message dispatch.

Error handling and message dispatch are fundamental to robust communication. This test validates:

- **Error conversion**: `JSONRPCError` responses properly raise `McpError` exceptions
- **Error data preservation**: Error code, message, and data are correctly embedded
- **Handler cleanup**: Response handlers are removed after both success and error (prevents memory leaks)
- **No hanging**: Errors don't cause infinite wait loops
- **Request dispatch**: Incoming requests are routed to registered handlers with correct parameters
- **Notification handling**: Incoming notifications invoke handlers without sending responses
- **JSON-RPC validation**: Messages with invalid JSON-RPC versions are rejected

**Key Tests**:
- `testSendRequestThrowsMcpErrorOnJsonRpcError()` - Validates error propagation flow
- `testSendRequestCleansUpHandlerOnSuccessResponse()` - Tests success path cleanup
- `testErrorResponseDoesNotHang()` - Ensures errors complete in < 1 second
- `testDifferentErrorCodesArePropagated()` - Tests 6 different error codes (JSON-RPC standard + custom)
- `testErrorWithNullDataIsHandled()` - Validates null error.data handling
- `testMultipleRequestsCleanUpHandlers()` - Prevents memory leaks over multiple requests
- `testIncomingRequestDispatchesThroughResponder()` - Tests server-side request routing and response correlation
- `testIncomingNotificationInvokesRegisteredHandlers()` - Tests fire-and-forget notification handling
- `testInvalidJsonRpcVersionRejectsMessage()` - Tests JSON-RPC 2.0 version validation

**Why Critical**: If error propagation is broken, clients will hang waiting for responses that never arrive, or leak memory from uncleaned handlers.

**Example Edge Case**: Server sends error instead of success → client must throw `McpError`, not hang indefinitely.

---

### 4. ServerMessageHandlingTest.php

**Location**: `tests/Server/ServerMessageHandlingTest.php`
**Source Code**: `src/Server/Server.php:176-252`

**Purpose**: Validates handler dispatch and error conversion (developer-facing API).

This test ensures the Server correctly routes messages to handlers and converts errors appropriately. It validates:

- **Handler invocation**: Registered handlers are called with correct parameters
- **McpError → ErrorResponse conversion**: Application errors are properly formatted for clients
- **Exception → InternalError conversion**: Unexpected exceptions don't crash the server
- **Missing handler → MethodNotFoundError**: Undefined methods return proper error codes
- **Notification handling**: Fire-and-forget messages don't send responses
- **Request ID correlation**: Responses match their requests

**Key Tests**:
- `testHandlerInvocationProducesResponse()` - Validates handler dispatch and response generation
- `testMcpErrorFromHandlerConvertedToJsonRpcError()` - Tests McpError → JSONRPCError conversion (code 42 → code 42)
- `testUnhandledExceptionConvertsToInternalError()` - Tests Exception → Internal Error (-32603)
- `testMissingHandlerProducesMethodNotFound()` - Validates Method Not Found error (-32601)
- `testNotificationHandlerIsInvokedWithoutResponse()` - Tests notification handling (no response sent)
- `testMissingNotificationHandlerDoesNotProduceError()` - Validates graceful handling of missing notification handlers
- `testMultipleHandlersCanBeRegistered()` - Ensures handler registry isolation
- `testHandlerWithNullParams()` - Tests handlers without parameters
- `testErrorResponseIncludesRequestId()` - Validates request ID correlation in error responses

**Why Critical**: Handler dispatch is the developer-facing API. If broken, servers cannot process ANY client requests.

---

## Writing New Tests

When adding new tests, follow these guidelines:

### Naming Conventions
- Test files: `{ClassName}Test.php` (e.g., `ClientSessionTest.php`)
- Test methods: `test{FunctionalityDescription}()` (e.g., `testHandlerInvocationProducesResponse()`)

### Documentation Standards
All test files should include:
1. **Class-level docblock** explaining overall test objectives
2. **Method-level docblocks** with flow explanations and source code references
3. **Inline comments** following Arrange/Act/Assert pattern
4. **Helper class documentation** for test doubles and mocks

### Test Structure
```php
public function testFeatureWorksCorrectly(): void
{
    // Arrange: Set up test conditions
    $session = new FakeSession();

    // Act: Execute the functionality being tested
    $result = $session->doSomething();

    // Assert: Verify expected outcomes
    $this->assertInstanceOf(ExpectedType::class, $result);
}
```

### Using Test Doubles
- Use **MemoryStream** for client tests (bidirectional in-memory communication)
- Use **InMemoryTransport** for server tests (implements Transport interface)
- Use **FakeSession** for BaseSession tests (extends BaseSession with message queue)
- Use **TestableServerSession** to expose BaseSession's protected methods for integration tests
- Use **TimeoutMemoryStream** to simulate timeout scenarios in client initialization
- Create minimal implementations of `McpModel` for test data

## Continuous Integration

Tests are automatically run on:
- Pull requests
- Commits to main branch
- Release candidate builds

All tests must pass before code can be merged.

## Troubleshooting

### Tests Fail After Update
```bash
# Clear composer cache and reinstall dependencies
composer clear-cache
composer install
```

### Cannot Find PHPUnit
```bash
# Ensure PHPUnit is installed
composer require --dev phpunit/phpunit
```

### Tests Hang or Timeout
Check for:
- Infinite loops in message processing
- Missing response handlers
- Incorrect request/response ID correlation

## Contributing

When contributing tests:

1. **Mirror the source structure**: Place tests in the same namespace as the code they test
2. **Follow documentation standards**: Match the quality of existing test documentation
3. **Test edge cases**: Don't just test the happy path
4. **Verify cleanup**: Ensure handlers, streams, and resources are properly cleaned up
5. **Run all tests**: Execute the full suite before submitting PRs

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [MCP Specification](https://spec.modelcontextprotocol.io/)
- [Project README](../README.md)